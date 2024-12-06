<?php

/**
 * Plugin Name: States, Cities, and Places for WooCommerce
 * Plugin URI: https://github.com/chitezh/woocommerce_states_places
 * Description: WooCommerce plugin for listing states, cities, places, local government areas and towns in all countries of the world.
 * Version: 1.4.0
 * Author: Kingsley Ochu
 * Author URI: https://github.com/chitezh
 * Developer: Kingsley Ochu
 * Developer URI: https://ng.linkedin.com/in/kingsleyochu/
 * Contributors: yordansoares, luisurrutiaf
 * Text Domain: states-cities-and-places-for-woocommerce
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.9
 * Tested up to: 6.4
 * WC requires at least: 8.0
 * WC tested up to: 9.4.3
 * Requires PHP: 7.4
 */

/**
 * Die if accessed directly
 */
defined('ABSPATH') or die(__('You can not access this file directly!', 'states-cities-and-places-for-woocommerce'));

/**
 * Check if WooCommerce is active
 */
if ((is_multisite() && array_key_exists('woocommerce/woocommerce.php', get_site_option('active_sitewide_plugins', array()))) ||
    in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))
) {

    class WC_States_Places
    {

        const VERSION = '1.4.0';
        private $states;
        private $places;

        /**
         * Construct class
         */
        public function __construct()
        {
            add_action('plugins_loaded', array($this, 'init'));
        }

        /**
         * WC init
         */
        public function init()
        {
            add_action('before_woocommerce_init', array($this, 'woocommerce_hpos_compatible'));

            $this->init_textdomain();
            $this->init_fields();
            $this->init_states();
            $this->init_places();
        }

        /**
         * Load text domain for internationalization
         */
        public function init_textdomain()
        {
            load_plugin_textdomain('states-cities-and-places-for-woocommerce', FALSE, dirname(plugin_basename(__FILE__)) . '/languages');
        }

        /**
         * WC Fields init
         */
        public function init_fields()
        {
            add_filter('woocommerce_default_address_fields', array($this, 'wc_change_state_and_city_order'));
        }

        /**
         * WC States init
         */
        public function init_states()
        {
            add_filter('woocommerce_states', array($this, 'wc_states'));
        }

        /**
         * WC Places init
         */
        public function init_places()
        {
            add_filter('woocommerce_billing_fields', array($this, 'wc_billing_fields'), 10, 2);
            add_filter('woocommerce_shipping_fields', array($this, 'wc_shipping_fields'), 10, 2);

            // Updated hook to use the more modern 'woocommerce_form_field'
            add_filter('woocommerce_form_field', array($this, 'wc_form_field_city'), 10, 4);

            add_action('wp_enqueue_scripts', array($this, 'load_scripts'));
        }

        /**
         * Change the order of State and City fields to have more sense with the steps of form
         * @param mixed $fields
         * @return mixed
         */
        public function wc_change_state_and_city_order($fields)
        {
            $fields['state']['priority'] = 70;
            $fields['city']['priority'] = 80;
            /* translators: Translate it to the name of the State level territory division, e.g. "State", "Province",  "Department" */
            $fields['state']['label'] = __('State', 'states-cities-and-places-for-woocommerce');
            /* translators: Translate it to the name of the City level territory division, e.g. "City, "Municipality", "District" */
            $fields['city']['label'] = __('City', 'states-cities-and-places-for-woocommerce');

            return $fields;
        }

        /**
         * Implement WC States
         * @param mixed $states
         * @return mixed
         */
        public function wc_states()
        {
            // Get countries allowed by store owner
            $allowed = $this->get_store_allowed_countries();

            $states = array();

            if (!empty($allowed)) {
                foreach ($allowed as $code => $country) {
                    $states_file = $this->get_plugin_path() . '/states/' . $code . '.php';
                    if (!isset($states[$code]) && file_exists($states_file)) {
                        include($states_file);
                    }
                }
            }

            return $states;
        }

        /**
         * Modify billing field
         * @param mixed $fields
         * @param mixed $country
         * @return mixed
         */
        public function wc_billing_fields($fields, $country)
        {
            $fields['billing_city']['type'] = 'city';
            return $fields;
        }

        /**
         * Modify shipping field
         * @param mixed $fields
         * @param mixed $country
         * @return mixed
         */
        public function wc_shipping_fields($fields, $country)
        {
            $fields['shipping_city']['type'] = 'city';
            return $fields;
        }

        /**
         * Implement places/city field
         * @param mixed $field
         * @param string $key
         * @param mixed $args
         * @param string $value
         * @return mixed
         */
        public function wc_form_field_city($field, $key, $args, $value)
        {
            // Check if this is a city field
            if ($args['type'] !== 'city') {
                return $field;
            }

            // Get Country
            $country_key = $key == 'billing_city' ? 'billing_country' : 'shipping_country';
            $current_cc = WC()->checkout->get_value($country_key);

            $state_key = $key == 'billing_city' ? 'billing_state' : 'shipping_state';
            $current_sc = WC()->checkout->get_value($state_key);

            // Get country places
            $places = $this->get_places($current_cc);

            if (is_array($places)) {
                $field = '';

                // Use WooCommerce's form-field.php template
                ob_start();
                woocommerce_form_field($key, array_merge($args, [
                    'type' => 'select',
                    'options' => $this->get_city_options($places, $current_sc),
                    'input_class' => array_merge(['city_select'], $args['input_class'] ?? []),
                    'custom_attributes' => $args['custom_attributes'] ?? [],
                ]), $value);
                $field = ob_get_clean();
            }

            return $field;
        }

        /**
         * Generate city options for dropdown
         * @param array $places
         * @param string $current_state
         * @return array
         */
        private function get_city_options($places, $current_state)
        {
            $options = ['' => __('Select an option&hellip;', 'woocommerce')];

            if ($current_state && array_key_exists($current_state, $places)) {
                $dropdown_places = $places[$current_state];
            } else if (is_array($places) && isset($places[0])) {
                $dropdown_places = $places;
                sort($dropdown_places);
            } else {
                $dropdown_places = $places;
            }

            foreach ($dropdown_places as $city_name) {
                if (!is_array($city_name)) {
                    $options[$city_name] = $city_name;
                }
            }

            return $options;
        }

        /**
         * Get places
         * @param string $p_code
         * @return mixed
         */
        public function get_places($p_code = null)
        {
            if (empty($this->places)) {
                $this->load_country_places();
            }

            if (!is_null($p_code)) {
                return isset($this->places[$p_code]) ? $this->places[$p_code] : false;
            } else {
                return $this->places;
            }
        }

        /**
         * Get country places
         * @return void
         */
        public function load_country_places()
        {
            global $places;

            $allowed = $this->get_store_allowed_countries();

            if ($allowed) {
                foreach ($allowed as $code => $country) {
                    $places_file = $this->get_plugin_path() . '/places/' . $code . '.php';
                    if (!isset($places[$code]) && file_exists($places_file)) {
                        include($places_file);
                    }
                }
            }

            $this->places = $places;
        }

        /**
         * Load scripts
         */
        public function load_scripts()
        {
            if (is_cart() || is_checkout() || is_wc_endpoint_url('edit-address')) {
                $script_path = $this->get_plugin_url() . 'js/place-select.js';
                wp_enqueue_script('wc-city-select', $script_path, array('jquery', 'woocommerce'), self::VERSION, true);

                $places = json_encode($this->get_places());
                wp_localize_script('wc-city-select', 'wc_city_select_params', array(
                    'cities' => $places,
                    'i18n_select_city_text' => esc_attr__('Select an option&hellip;', 'woocommerce')
                ));
            }
        }

        /**
         * Get plugin root path
         * @return string
         */
        private function get_plugin_path()
        {
            return untrailingslashit(plugin_dir_path(__FILE__));
        }

        /**
         * Get Store allowed countries
         * @return array
         */
        private function get_store_allowed_countries()
        {
            return array_merge(WC()->countries->get_allowed_countries(), WC()->countries->get_shipping_countries());
        }

        /**
         * Get plugin url
         * @return string
         */
        public function get_plugin_url()
        {
            return plugin_dir_url(__FILE__);
        }

        /**
         * Declares WooCommerce HPOS compatibility
         *
         * @return void
         */
        public function woocommerce_hpos_compatible()
        {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        }
    }

    /**
     * Instantiate class
     */
    $GLOBALS['wc_states_places'] = new WC_States_Places();
}
