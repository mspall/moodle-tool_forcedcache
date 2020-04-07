<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * This cache_config class extends the first one, and generates the
 * configuration array from reading a hardcoded JSON file instead of
 * the configuration file on shared disk.
 *
 * @package     tool_forcedcache
 * @author      Peter Burnett <peterburnett@catalyst-au.net>
 * @copyright   Catalyst IT
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_forcedcache_cache_config extends cache_config {

    /**
     * This is a wrapper function that simply wraps around include_configuration,
     * and returns any exception messages.
     */
    public function get_inclusion_errors() {
        global $SESSION;
        unset($SESSION->tool_forcedcache_caching_exception);
        $this->include_configuration();
        if (!empty($SESSION->tool_forcedcache_caching_exception)) {
            $data = $SESSION->tool_forcedcache_caching_exception;
            unset($SESSION->tool_forcedcache_caching_exception);
        } else {
            $data = '';
        }
        return $data;
    }

    /**
     * This is where the magic happens. Instead of loading a file,
     * this generates rulesets based on a JSON file, and binds them to definitions.
     * If there are any errors during this process, it aborts and falls back to core configuration.
     *
     * @return array the configuration array.
     */
    protected function include_configuration() {
        global $CFG, $SESSION;

        try {
            return $this->generate_config_array();
        } catch (Exception $e) {
            // Store the error message in session, helps with debugging from a frontend display.
            // This may be overwritten depending on load order. Best to create a dummy cache instance then check.
            $SESSION->tool_forcedcache_caching_exception = $e->getMessage();
            // Also store a canary for the writer to know if things are borked.
            $CFG->tool_forcedcache_config_broken = true;
            return parent::include_configuration();
        }
    }

    /**
     * This is a glue function that grabs all of the different components of the configuration,
     * and stitches them together.
     *
     * @return array the cache configuration array.
     */
    private function generate_config_array() {
        global $CFG;
        // READFILE
        // Path can only be hardcoded, to avoid concurrency issues between frontends.
        if (!empty($CFG->tool_forcedcache_config_path)) {
            $path = $CFG->tool_forcedcache_config_path;
        } else {
            $path = __DIR__.'/../config.json';
        }
        $config = $this->read_config_file($path);
        // GENERATE STORES CONFIG
        $stores = $this->generate_store_instance_config($config['stores']);

        //GENERATE MODE MAPPINGS
        $modemappings = $this->generate_mode_mapping($config['rules']);

        // GENERATE DEFINITIONS
        $definitions = tool_forcedcache_cache_config_writer::locate_definitions();

        // GENERATE DEFINITIONS FROM RULESETS
        $definitionmappings = $this->generate_definition_mappings_from_rules($config['rules'], $definitions);

        // GENERATE LOCKS
        $locks = $this->generate_locks();

        // GENERATE SITEIDENTIFIER
        $siteidentifier = cache_helper::get_site_identifier();

        //Throw it all into an array and return
        return array(
            'siteidentifier' => $siteidentifier,
            'stores' => $stores,
            'modemappings' => $modemappings,
            'definitions' => $definitions,
            'definitionmappings' => $definitionmappings,
            'locks' => $locks
        );
    }

    /**
     * This reads the JSON file at $path and parses it for use in cache generation.
     * Exceptions are thrown so that caching will fallback to core.
     *
     * @param string $path the path of the config file to read.
     * @return array Associative array of configuration from JSON.
     * @throws cache_exception
     */
    private function read_config_file($path) {
        if (file_exists($path)) {
            $filedata = file_get_contents($path);
            $config = json_decode($filedata, true);
            if (!empty($config)) {
                return $config;
            } else {
                throw new cache_exception(get_string('config_json_parse_fail', 'tool_forcedcache'));
            }
        } else {
            throw new cache_exception(get_string('config_json_missing', 'tool_forcedcache'));
        }
    }

    /**
     * This instantiates any stores defined in the config,
     * and the default stores, which must always exist.
     *
     * @param array $stores the array of stores declared in the JSON file.
     * @return array a mapped configuration array of store instances.
     * @throws cache_exception
     */
    private function generate_store_instance_config($stores) {
        $storesarr = array();
        foreach ($stores as $name => $store) {

            // First check that all the required fields are present in the store.
            if (!(array_key_exists('type', $store) ||
                  array_key_exists('config', $store))) {
                throw new cache_exception(get_string('store_missing_fields', 'tool_forcedcache', $name));
            }

            $storearr = array();
            $storearr['name'] = $name;
            $storearr['plugin'] = $store['type'];
            // Assume all configuration is correct.
            // If anything borks, we will fallback to core caching.
            $storearr['configuration'] = $store['config'];
            $classname = 'cachestore_'.$store['type'];
            $storearr['class'] = $classname;

            // Now for the derived config from the store information provided.
            // Manually require the cache/lib.php file to get cache classes.
            $cachepath = __DIR__.'/../../../../cache/stores/' . $store['type'] . '/lib.php';
            if (!file_exists($cachepath)) {
                throw new cache_exception(get_string('store_bad_type', 'tool_forcedcache', $store['type']));
            }
            require_once($cachepath);
            $storearr['features'] = $classname::get_supported_features();
            $storearr['modes'] = $classname::get_supported_modes();

            // Set these to a default value.
            $storearr['default'] = false;
            $storearr['mappingsonly'] = 'false';
            $storearr['lock'] = 'cachelock_file_default';

            // TODO cycle through any remaining config and instantiate it.

            $storesarr[$name] = $storearr;
        }

        // Now instantiate the default stores (Must always exist).
        $storesarr = array_merge($storesarr, tool_forcedcache_cache_config_writer::get_default_stores());

        return $storesarr;
    }

    /**
     * This function generates the default mappings for each cache mode.
     *
     * @param array $rules the rules array from the JSON.
     * @return array the generated default mode mappings.
     */
    private function generate_mode_mapping($rules) {
        // Here we must decide on how the stores are going to be used
        $modemappings = array();

        // LEAVE HERE. This needs rethinking re whether it is useful/what rules to apply this to.
        //$sort = 0;
        /*$modemappings = array_merge($modemappings,
            $this->create_mappings($rules['application'], cache_store::MODE_APPLICATION, $sort));
        $sort = count($modemappings);

        // Now do the exact same for Session.
        $modemappings = array_merge($modemappings,
            $this->create_mappings($rules['session'], cache_store::MODE_SESSION, $sort));
        $sort += count($modemappings);

        // Finally for Request.
        $modemappings = array_merge($modemappings,
            $this->create_mappings($rules['request'], cache_store::MODE_REQUEST, $sort));
        */

        // USE THIS IF NOT USING ABOVE
        // TODO Finally, instantiate the defaults.
        $modemappings = array_merge($modemappings, array(
            array(
                'mode' => cache_store::MODE_APPLICATION,
                'store' => 'default_application',
                'sort' => -1
            ),
            array(
                'mode' => cache_store::MODE_SESSION,
                'store' => 'default_session',
                'sort' => -1
            ),
            array(
                'mode' => cache_store::MODE_REQUEST,
                'store' => 'default_request',
                'sort' => -1
            )
            ));

        return $modemappings;
    }

    /**
     * This generates an ordered list of default mappings for each mode,
     * based on the declared stores, and the rules.
     *
     * @param array $rules the rules array from JSON.
     * @param int $mode the mode the store will map to.
     * @param int $sortstart the index to start creating array keys at in the return array.
     * @return array an array of mapped stores->mode
     * @throws cache_exception
     */
    private function create_mappings($rules, $mode, $sortstart) {
        // Check all 3 modes sequentially.
        // TODO Check mode is supported by store and exception.
        // TODO Check store exists before mapping it.
        // TODO Ensure sorting isnt borked. Shouldnt matter, as we will explicitly bind it.
        // TODO Ensure config.json is properly formed/ordered (indexes)

        $mappedstores = array();
        $sort = $sortstart;

        if (count($rules) === 0) {
            return array();
        }

        foreach ($rules['local'] as $key => $mapping) {
            // Create the mapping.
            $maparr = [];
            $maparr['mode'] = $mode;
            $maparr['store'] = $mapping;
            $maparr['sort'] = $sort;
            $modemappings[$sort] = $maparr;
            $sort++;

            // Now store the mapping name and mode to prevent duplication.
            $mappedstores[] = $mapping;
        }

        // Now we construct the non-locals, after checking they aren't already mapped.
        foreach ($rules['non-local'] as $key => $mapping) {
            if (in_array($mapping, $mappedstores)) {
                continue;
            }

            // Create the mapping.
            $maparr = [];
            $maparr['mode'] = $mode;
            $maparr['store'] = $mapping;
            $maparr['sort'] = $sort;
            $modemappings[$sort] = $maparr;
            $sort++;

            // Now store the mapping name and mode to prevent duplication.
            $mappedstores[] = $mapping;
        }

        return $modemappings;
    }

    /**
     * This function takes the rules and definitions,
     * and creates mappings from definition->store(s)
     * based on a fallthrough pattern of the rules
     * from the JSON file.
     *
     * @param array $rules the rules array from the JSON
     * @param array $definitions a list of definitions to map.
     * @return array an array of ordered mappings for every definition to its ruleset.
     */
    private function generate_definition_mappings_from_rules($rules, $definitions) {
        $defmappings = array();
        $num = 1;
        foreach ($definitions as $defname => $definition) {
            // Find the mode of the definition to discover the mappings.
            $mode = $definition['mode'];

            // Decide on ruleset based on mode.
            switch ($mode) {
                case cache_store::MODE_APPLICATION:
                    $ruleset = $rules['application'];
                    break;

                case cache_store::MODE_SESSION:
                    $ruleset = $rules['session'];
                    break;

                case cache_store::MODE_REQUEST:
                    $ruleset = $rules['request'];
            }

            // If no rules are specified for a type,
            // Skip this definition to fall through to defaults.
            if (count($ruleset) === 0) {
                continue;
            }

            // Now decide on the ruleset that matches.
            $stores = array();
            foreach ($ruleset as $rule) {
                if (array_key_exists('conditions', $rule)) {
                    foreach ($rule['conditions'] as $condition => $value) {
                        // Precompute some checks to construct a clean bool condition.
                        $conditionmatches = array_key_exists($condition, $definition) && $value === $definition[$condition];
                        $namematches = ($condition === 'name') && ($defname === $value);

                        // Check if condition isn't present in definition or doesn't match.
                        // If nothing matches, jump out of this ruleset entirely.
                        if (!($conditionmatches || $namematches)) {
                            continue 2;
                        }
                    }
                }

                // If we get here, there are no conditions in this ruleset, or every one was a match.
                // We can safely bind stores, then break.
                $stores = $rule['stores'];
                break;
            }

            // Weirdness here. Some stuff sorts lowest as priority, Mappings sort highest as priority.
            $sort = count($stores);
            foreach ($stores as $store) {
                //Create the mapping for the definition -> store and add to the master list.
                $mappingarr = array();
                $mappingarr['store'] = $store;
                $mappingarr['definition'] = $defname;
                $mappingarr['sort'] = $sort;

                $defmappings[$num] = $mappingarr;

                // Increment the mapping counter, decrement local sorting counter for definition.
                $sort--;
                $num++;
            }
        }
        return $defmappings;
    }

    /**
     * This is a copy of the default locking configuration used by core.
     * TODO figure out whether locking needs to be implemented in a robust way.
     *
     * @return array array of locks to use.
     */
    private function generate_locks() {
        return array(
            'default_file_lock' => array(
                'name' => 'cachelock_file_default',
                'type' => 'cachelock_file',
                'dir' => 'filelocks',
                'default' => true
            )
        );
    }
}
