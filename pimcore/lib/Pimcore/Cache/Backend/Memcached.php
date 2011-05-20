<?php 
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */


class Pimcore_Cache_Backend_Memcached extends Zend_Cache_Backend_Memcached {

    private $db;
    
    private function getDb () {
        if(!$this->db) {
            $this->db = Pimcore_Resource::get();
        }
        return $this->db;
    }
    
    
    private function removeTag($tag) {
        $this->getDb()->delete("cache_tags", "tag = '".$tag."'");
    }

    private function saveTags($id, $tags) {
        
        foreach ($tags as $tag) {
            try {
                $this->getDb()->insert("cache_tags", array(
                    "id" => $id, 
                    "tag" => $tag
                ));
            }
            catch (Exception $e) {
                // already exists
            } 
        }
    }
    
    private function clearTags () {
        $this->getDb()->delete("cache_tags");
    }

    private function getItemsByTag($tag) {
        $itemIds = $this->getDb()->fetchAll("SELECT id FROM cache_tags WHERE tag = '" . $tag . "'");
        $items = array();
        
        foreach ($itemIds as $item) {
            $items[] = $item["id"];
        }
        
        
        return $items;
    }

    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  int    $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return boolean True if no problem
     */
    public function save($data, $id, $tags = array(), $specificLifetime = false) {
        $lifetime = $this->getLifetime($specificLifetime);
        if ($this->_options['compression']) {
            $flag = MEMCACHE_COMPRESSED;
        } else {
            $flag = 0;
        }
        
        // hack may it works also without it
        $this->_memcache->delete($id);
        // hack end
        
        $result = $this->_memcache->replace($id, array($data, time()), $flag, $lifetime);
        if( $result == false ) { 
            $result = $this->_memcache->set($id, array($data, time()), $flag, $lifetime);
        } 
        
        
        if (count($tags) > 0) {
            $this->saveTags($id, $tags);
        }
        return $result;
    }

    /** 
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array()) {
        $success = true;

        if ($mode == Zend_Cache::CLEANING_MODE_ALL) {
            $this->clearTags();
            return $this->_memcache->flush();
        }
        if ($mode == Zend_Cache::CLEANING_MODE_OLD) {
            Logger::warning("Zend_Cache_Backend_Memcached::clean() : CLEANING_MODE_OLD is unsupported by the Memcached backend");
        }

        if ($mode == Zend_Cache::CLEANING_MODE_MATCHING_TAG || $mode == Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG) {
            foreach ($tags as $tag) {
                $items = $this->getItemsByTag($tag);
                foreach ($items as $item) {
                    // We call delete directly here because the ID in the cache is already specific for this site
                    //$this->_memcache->delete($item);
                    $_success = $this->remove($item);
                    if(!$_success) {
                        $success = false;
                    }
                }
                //$this->removeTag($tag);
            }
        }
        if ($mode == Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG) {

            $condParts = array("1=1");
            foreach ($tags as $tag) {
                $condParts[] = "tag != '" . $tag . "'";
            }

            $itemIds = $this->getDb()->fetchAll("SELECT id FROM cache_tags WHERE ".implode(" AND ",$condParts));
            //$this->getDb()->delete(implode(" AND ",$condParts));

            $items = array();
            foreach ($itemIds as $item) {
                $items[] = $item["id"];
            }

            foreach ($items as $item) {
               $_success = $this->remove($item);
                if(!$_success) {
                    $success = false;
                }
                //$this->_memcache->delete($item);
            }
        }

        return $success;
    }

    
    
    /**
     * @param  string $id
     * @return array tags for given id
     */
    protected function getTagsById($id) {
        $itemIds = $this->getDb()->fetchAll("SELECT tag FROM cache_tags WHERE id = '" . $id . "'");
        $items = array();

        foreach ($itemIds as $item) {
            $items[] = $item["tag"];
        }
        return $items;
    }

    /**
     * @param  string $id
     * @return bool true if OK
     */
    public function remove($id) {
        /*$tags = $this->getTagsById($id);
        foreach($tags as $tag) {
            $this->removeTag($tag);
        }*/
        
        $this->getDb()->delete("cache_tags", "id = '".$id."'");
                
        return parent::remove($id);
    }


    public function getCapabilities() {
        $capabilities = parent::getCapabilities();
        $capabilities["tags"] = true;

        return $capabilities;
    }
}
