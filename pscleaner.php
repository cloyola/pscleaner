<?php
/**
 * 2007-2026 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2026 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PSCleaner extends Module
{
    /** @var string */
    public $secure_key;

    public function __construct()
    {
        $this->name = 'pscleaner';
        $this->tab = 'administration';
        $this->version = '2.2.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;
        $this->multishop_context = Shop::CONTEXT_ALL;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => '9.99.99');

        parent::__construct();

        if (defined('Module::MULTISTORE_COMPATIBILITY_NO')) {
            $this->multistoreCompatibility = self::MULTISTORE_COMPATIBILITY_NO;
        }

        $this->displayName = $this->trans('PrestaShop Cleaner', array(), 'Modules.Pscleaner.Admin');
        $this->description = $this->trans('PrestaShop helps you remove your catalog, orders and customers data in one go.', array(), 'Modules.Pscleaner.Admin');
        $this->secure_key = self::hashKey($this->name);
    }

    protected function getMultiShopValues($key)
    {
        return Configuration::getMultiShopValues($key);
    }

    public function getContent()
    {
        $html = '<h2>'.$this->trans('Be really careful with this tool - There is no possible rollback!', array(), 'Modules.Pscleaner.Admin').'</h2>';
        if (Tools::isSubmit('submitCheckAndFix')) {
            $logs = self::checkAndFix();
            if (count($logs)) {
                $conf = $this->trans('The following queries successfuly fixed broken data:', array(), 'Modules.Pscleaner.Admin').'<br /><ul>';
                foreach ($logs as $query => $entries) {
                    $conf .= '<li>'.Tools::htmlentitiesUTF8($query).'<br />'.$this->trans('%d line(s)', array($entries), 'Modules.Pscleaner.Admin').'</li>';
                }
                $conf .= '</ul>';
            } else {
                $conf = $this->trans('Nothing that need to be fixed', array(), 'Modules.Pscleaner.Admin');
            }
            $html .= $this->displayConfirmation($conf);
        } elseif (Tools::isSubmit('submitCleanAndOptimize')) {
            $logs = self::cleanAndOptimize();
            if (count($logs)) {
                $conf = $this->trans('The following queries successfuly cleaned your database:', array(), 'Modules.Pscleaner.Admin').'<br /><ul>';
                foreach ($logs as $query => $entries) {
                    $conf .= '<li>'.Tools::htmlentitiesUTF8($query).'<br />'.$this->trans('%d line(s)', array($entries), 'Modules.Pscleaner.Admin').'</li>';
                }
                $conf .= '</ul>';
            } else {
                $conf = $this->trans('Nothing that need to be cleaned', array(), 'Modules.Pscleaner.Admin');
            }
            $html .= $this->displayConfirmation($conf);
        } elseif (Tools::getValue('submitTruncateCatalog') && Tools::getValue('checkTruncateCatalog')) {
            self::truncate('catalog');
            $html .= $this->displayConfirmation($this->trans('Catalog truncated', array(), 'Modules.Pscleaner.Admin'));
        } elseif (Tools::getValue('submitTruncateSales') && Tools::getValue('checkTruncateSales')) {
            self::truncate('sales');
            $html .= $this->displayConfirmation($this->trans('Orders and customers truncated', array(), 'Modules.Pscleaner.Admin'));
        }

        $confirmCatalog = json_encode($this->trans('Please read the disclaimer and click "Yes" above', array(), 'Modules.Pscleaner.Admin'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $confirmCatalogDelete = json_encode($this->trans('Are you sure that you want to delete all catalog data?', array(), 'Modules.Pscleaner.Admin'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $confirmSales = json_encode($this->trans('Please read the disclaimer and click "Yes" above', array(), 'Modules.Pscleaner.Admin'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $confirmSalesDelete = json_encode($this->trans('Are you sure that you want to delete all sales data?', array(), 'Modules.Pscleaner.Admin'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $html .= '
		<script type="text/javascript">
			$(document).ready(function(){
				$("#submitTruncateCatalog").click(function(){
					if (!$(\'#checkTruncateCatalog_on\').is(\':checked\')) {
						alert('.$confirmCatalog.');
						return false;
					}
					return window.confirm('.$confirmCatalogDelete.');
				});
				$("#submitTruncateSales").click(function(){
					if (!$(\'#checkTruncateSales_on\').is(\':checked\')) {
						alert('.$confirmSales.');
						return false;
					}
					return window.confirm('.$confirmSalesDelete.');
				});
			});
		</script>';

        return $html.$this->renderForm();
    }

    public static function checkAndFix()
    {
        $db = Db::getInstance();
        $logs = array();

        $filteredConfiguration = array();
        $result = self::fetchRows('SELECT * FROM `'._DB_PREFIX_.'configuration`');
        foreach ($result as $row) {
            $shopGroup = isset($row['id_shop_group']) ? $row['id_shop_group'] : '';
            $shop = isset($row['id_shop']) ? $row['id_shop'] : '';
            $name = isset($row['name']) ? $row['name'] : '';
            $key = (string) $shopGroup.'-|-'.(string) $shop.'-|-'.(string) $name;
            if (isset($filteredConfiguration[$key])) {
                $query = 'DELETE FROM `'._DB_PREFIX_.'configuration` WHERE id_configuration = '.(int) $row['id_configuration'];
                self::runQuery($db, $query, $logs);
            } else {
                $filteredConfiguration[$key] = true;
            }
        }
        unset($filteredConfiguration);

        $query = 'DELETE FROM `'._DB_PREFIX_.'configuration_lang`
		WHERE `id_configuration` NOT IN (SELECT `id_configuration` FROM `'._DB_PREFIX_.'configuration`)
		OR `id_configuration` IN (SELECT `id_configuration` FROM `'._DB_PREFIX_.'configuration` WHERE name IS NULL OR name = "")';
        if (self::tableExists('configuration_lang') && self::tableExists('configuration')) {
            self::runQuery($db, $query, $logs);
        }

        $queries = self::bulle(self::getCheckAndFixQueries());
        foreach ($queries as $queryArray) {
            if (isset($queryArray[4]) && !Module::isInstalled($queryArray[4])) {
                continue;
            }
            if (!self::tableExists($queryArray[0]) || !self::tableExists($queryArray[2])) {
                continue;
            }
            if (!self::columnExists($queryArray[0], $queryArray[1]) || !self::columnExists($queryArray[2], $queryArray[3])) {
                continue;
            }

            $query = 'DELETE FROM `'._DB_PREFIX_.bqSQL($queryArray[0]).'` WHERE `'.bqSQL($queryArray[1]).'` NOT IN (SELECT `'.bqSQL($queryArray[3]).'` FROM `'._DB_PREFIX_.bqSQL($queryArray[2]).'`)';
            self::runQuery($db, $query, $logs);
        }

        self::cleanSuffixedTables($db, '_lang', 'id_lang', 'lang', $logs, array());
        self::cleanSuffixedTables($db, '_shop', 'id_shop', 'shop', $logs, array(_DB_PREFIX_.'carrier_tax_rules_group_shop'));

        if (self::tableExists('stock_available') && self::tableExists('shop') && self::tableExists('shop_group')
            && self::columnExists('stock_available', 'id_shop') && self::columnExists('stock_available', 'id_shop_group')
            && self::columnExists('shop', 'id_shop') && self::columnExists('shop_group', 'id_shop_group')
        ) {
            // id_shop = 0 and id_shop_group = 0 are the shared-stock rows, not orphans.
            $query = 'DELETE FROM `'._DB_PREFIX_.'stock_available`
			WHERE (`id_shop` != 0 AND `id_shop` NOT IN (SELECT `id_shop` FROM `'._DB_PREFIX_.'shop`))
			OR (`id_shop_group` != 0 AND `id_shop_group` NOT IN (SELECT `id_shop_group` FROM `'._DB_PREFIX_.'shop_group`))';
            self::runQuery($db, $query, $logs);
        }

        if (method_exists('Category', 'regenerateEntireNtree')) {
            try {
                Category::regenerateEntireNtree();
            } catch (Exception $e) {
            }
        }

        if (method_exists('Image', 'clearTmpDir')) {
            try {
                Image::clearTmpDir();
            } catch (Exception $e) {
            }
        }
        self::clearAllCaches();

        return $logs;
    }

    public static function truncate($case)
    {
        $db = Db::getInstance();
        self::runQuery($db, 'SET FOREIGN_KEY_CHECKS = 0');

        try {
            switch ($case) {
                case 'catalog':
                    self::truncateCatalog($db);
                    break;

                case 'sales':
                    self::truncateSales($db);
                    break;
            }
            self::clearAllCaches();
        } finally {
            self::runQuery($db, 'SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public static function cleanAndOptimize()
    {
        $logs = array();
        $db = Db::getInstance();

        $query = '
		DELETE FROM `'._DB_PREFIX_.'cart`
		WHERE id_cart NOT IN (SELECT id_cart FROM `'._DB_PREFIX_.'orders`)
		AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"';
        if (self::tableExists('cart') && self::tableExists('orders') && self::columnExists('cart', 'date_add')) {
            self::runQuery($db, $query, $logs);
        }

        $query = '
		DELETE FROM `'._DB_PREFIX_.'cart_rule`
		WHERE (
			active = 0
			OR quantity = 0
			OR date_to < "'.pSQL(date('Y-m-d')).'"
		)
		AND date_add < "'.pSQL(date('Y-m-d', strtotime('-1 month'))).'"';
        if (self::tableExists('cart_rule') && self::columnExists('cart_rule', 'date_add') && self::columnExists('cart_rule', 'date_to')) {
            self::runQuery($db, $query, $logs);
        }

        if (self::tableExists('tab') && self::columnExists('tab', 'id_parent') && self::columnExists('tab', 'position')) {
            $parents = self::fetchRows('SELECT DISTINCT id_parent FROM `'._DB_PREFIX_.'tab`');
            foreach ($parents as $parent) {
                $orderBy = self::columnExists('tab', 'class_name')
                    ? ' ORDER BY IF(class_name IN ("AdminHome", "AdminDashboard"), 1, 2), position ASC'
                    : ' ORDER BY position ASC';
                $children = self::fetchRows('SELECT id_tab FROM `'._DB_PREFIX_.'tab` WHERE id_parent = '.(int) $parent['id_parent'].$orderBy);
                $position = 1;
                foreach ($children as $child) {
                    $query = 'UPDATE `'._DB_PREFIX_.'tab` SET position = '.(int) $position.' WHERE id_tab = '.(int) $child['id_tab'].' AND id_parent = '.(int) $parent['id_parent'];
                    ++$position;
                    self::runQuery($db, $query, $logs);
                }
            }
        }

        return $logs;
    }

    protected static function bulle($array)
    {
        $sorted = false;
        $size = count($array);
        while (!$sorted) {
            $sorted = true;
            for ($i = 0; $i < $size - 1; ++$i) {
                for ($j = $i + 1; $j < $size; ++$j) {
                    if ($array[$i][2] == $array[$j][0]) {
                        $tmp = $array[$i];
                        $array[$i] = $array[$j];
                        $array[$j] = $tmp;
                        $sorted = false;
                    }
                }
            }
        }

        return $array;
    }

    protected static function clearAllCaches()
    {
        if (defined('_PS_TMP_IMG_DIR_') && is_dir(_PS_TMP_IMG_DIR_)) {
            $indexFile = _PS_TMP_IMG_DIR_.'index.php';
            $index = is_file($indexFile) ? file_get_contents($indexFile) : '';
            if (!is_string($index)) {
                $index = '';
            }
            if (method_exists('Tools', 'deleteDirectory')) {
                Tools::deleteDirectory(_PS_TMP_IMG_DIR_, false);
            }
            if ($index !== '') {
                @file_put_contents($indexFile, $index);
            }
        }

        $context = Context::getContext();
        if (isset($context->smarty) && is_object($context->smarty) && method_exists($context->smarty, 'clearAllCache')) {
            $context->smarty->clearAllCache();
        }

        if (class_exists('Cache') && method_exists('Cache', 'clean')) {
            try {
                Cache::clean('*');
            } catch (Exception $e) {
            }
        }

        if (method_exists('Tools', 'clearSf2Cache')) {
            try {
                Tools::clearSf2Cache();
            } catch (Exception $e) {
            }
        }
    }

    public function renderForm()
    {
        $fieldsForm1 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Catalog', array(), 'Modules.Pscleaner.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->trans('I understand that all the catalog data will be removed without possible rollback: products, features, categories, tags, images, prices, attachments, scenes, stocks, attribute groups and values, manufacturers, suppliers...', array(), 'Modules.Pscleaner.Admin'),
                        'name' => 'checkTruncateCatalog',
                        'values' => array(
                            array(
                                'id' => 'checkTruncateCatalog_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global')
                            ),
                            array(
                                'id' => 'checkTruncateCatalog_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global')
                            )
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->trans('Delete catalog', array(), 'Modules.Pscleaner.Admin'),
                    'class' => 'btn btn-default pull-right float-right',
                    'name' => 'submitTruncateCatalog',
                    'id' => 'submitTruncateCatalog',
                )
            )
        );

        $fieldsForm2 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Orders and customers', array(), 'Modules.Pscleaner.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'is_bool' => true,
                        'label' => $this->trans('I understand that all the orders and customers will be removed without possible rollback: customers, carts, orders, connections, guests, messages, stats...', array(), 'Modules.Pscleaner.Admin'),
                        'name' => 'checkTruncateSales',
                        'values' => array(
                            array(
                                'id' => 'checkTruncateSales_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', array(), 'Admin.Global')
                            ),
                            array(
                                'id' => 'checkTruncateSales_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', array(), 'Admin.Global')
                            )
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->trans('Delete orders & customers', array(), 'Modules.Pscleaner.Admin'),
                    'class' => 'btn btn-default pull-right float-right',
                    'name' => 'submitTruncateSales',
                    'id' => 'submitTruncateSales',
                )
            )
        );

        $fieldsForm3 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Functional integrity constraints', array(), 'Modules.Pscleaner.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'submit' => array(
                    'title' => $this->trans('Check & fix', array(), 'Modules.Pscleaner.Admin'),
                    'class' => 'btn btn-default pull-right float-right',
                    'name' => 'submitCheckAndFix',
                )
            )
        );
        $fieldsForm4 = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Database cleaning', array(), 'Modules.Pscleaner.Admin'),
                    'icon' => 'icon-cogs'
                ),
                'submit' => array(
                    'title' => $this->trans('Clean & Optimize', array(), 'Modules.Pscleaner.Admin'),
                    'class' => 'btn btn-default pull-right float-right',
                    'name' => 'submitCleanAndOptimize',
                )
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        if ($defaultLangId <= 0 && isset($this->context->language->id)) {
            $defaultLangId = (int) $this->context->language->id;
        }
        $helper->default_form_language = $defaultLangId;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->id = (int) Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->getFormLanguages(),
            'id_language' => (isset($this->context->language->id) ? (int) $this->context->language->id : $defaultLangId)
        );

        return $helper->generateForm(array($fieldsForm1, $fieldsForm2, $fieldsForm3, $fieldsForm4));
    }

    public function getConfigFieldsValues()
    {
        return array('checkTruncateSales' => 0, 'checkTruncateCatalog' => 0);
    }

    public static function getCheckAndFixQueries()
    {
        return array(
            // 0 => DELETE FROM __table__, 1 => WHERE __id__ NOT IN, 2 => NOT IN __table__, 3 => __id__ used in the "NOT IN" table, 4 => module_name
            array('access', 'id_profile', 'profile', 'id_profile'),
            array('accessory', 'id_product_1', 'product', 'id_product'),
            array('accessory', 'id_product_2', 'product', 'id_product'),
            array('address_format', 'id_country', 'country', 'id_country'),
            array('attribute', 'id_attribute_group', 'attribute_group', 'id_attribute_group'),
            array('carrier_group', 'id_carrier', 'carrier', 'id_carrier'),
            array('carrier_group', 'id_group', 'group', 'id_group'),
            array('carrier_zone', 'id_carrier', 'carrier', 'id_carrier'),
            array('carrier_zone', 'id_zone', 'zone', 'id_zone'),
            array('cart_cart_rule', 'id_cart', 'cart', 'id_cart'),
            array('cart_cart_rule', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_product', 'id_cart', 'cart', 'id_cart'),
            array('cart_rule_carrier', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_carrier', 'id_carrier', 'carrier', 'id_carrier'),
            array('cart_rule_combination', 'id_cart_rule_1', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_combination', 'id_cart_rule_2', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_country', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_country', 'id_country', 'country', 'id_country'),
            array('cart_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_group', 'id_group', 'group', 'id_group'),
            array('cart_rule_lang', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_lang', 'id_lang', 'lang', 'id_lang'),
            array('cart_rule_shop', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_shop', 'id_shop', 'shop', 'id_shop'),
            array('cart_rule_product_rule_group', 'id_cart_rule', 'cart_rule', 'id_cart_rule'),
            array('cart_rule_product_rule', 'id_product_rule_group', 'cart_rule_product_rule_group', 'id_product_rule_group'),
            array('cart_rule_product_rule_value', 'id_product_rule', 'cart_rule_product_rule', 'id_product_rule'),
            array('category_group', 'id_category', 'category', 'id_category'),
            array('category_group', 'id_group', 'group', 'id_group'),
            array('category_product', 'id_category', 'category', 'id_category'),
            array('category_product', 'id_product', 'product', 'id_product'),
            array('cms', 'id_cms_category', 'cms_category', 'id_cms_category'),
            array('cms_block', 'id_cms_category', 'cms_category', 'id_cms_category', 'blockcms'),
            array('cms_block_page', 'id_cms', 'cms', 'id_cms', 'blockcms'),
            array('cms_block_page', 'id_cms_block', 'cms_block', 'id_cms_block', 'blockcms'),
            array('connections', 'id_shop_group', 'shop_group', 'id_shop_group'),
            array('connections', 'id_shop', 'shop', 'id_shop'),
            array('connections_page', 'id_connections', 'connections', 'id_connections'),
            array('connections_page', 'id_page', 'page', 'id_page'),
            array('connections_source', 'id_connections', 'connections', 'id_connections'),
            array('customer', 'id_shop_group', 'shop_group', 'id_shop_group'),
            array('customer', 'id_shop', 'shop', 'id_shop'),
            array('customer_group', 'id_group', 'group', 'id_group'),
            array('customer_group', 'id_customer', 'customer', 'id_customer'),
            array('customer_message', 'id_customer_thread', 'customer_thread', 'id_customer_thread'),
            array('customer_thread', 'id_shop', 'shop', 'id_shop'),
            array('customer_session', 'id_customer', 'customer', 'id_customer'),
            array('customer_b2b', 'id_customer', 'customer', 'id_customer'),
            array('business_entity_customer_b2b', 'id_customer_b2b', 'customer_b2b', 'id_customer_b2b'),
            array('customization', 'id_cart', 'cart', 'id_cart'),
            array('customization_field', 'id_product', 'product', 'id_product'),
            array('customized_data', 'id_customization', 'customization', 'id_customization'),
            array('delivery', 'id_shop', 'shop', 'id_shop'),
            array('delivery', 'id_shop_group', 'shop_group', 'id_shop_group'),
            array('delivery', 'id_carrier', 'carrier', 'id_carrier'),
            array('delivery', 'id_zone', 'zone', 'id_zone'),
            array('editorial', 'id_shop', 'shop', 'id_shop', 'editorial'),
            array('favorite_product', 'id_product', 'product', 'id_product', 'favoriteproducts'),
            array('favorite_product', 'id_customer', 'customer', 'id_customer', 'favoriteproducts'),
            array('favorite_product', 'id_shop', 'shop', 'id_shop', 'favoriteproducts'),
            array('feature_product', 'id_feature', 'feature', 'id_feature'),
            array('feature_product', 'id_product', 'product', 'id_product'),
            array('feature_value', 'id_feature', 'feature', 'id_feature'),
            array('group_reduction', 'id_group', 'group', 'id_group'),
            array('group_reduction', 'id_category', 'category', 'id_category'),
            array('homeslider', 'id_shop', 'shop', 'id_shop', 'homeslider'),
            array('homeslider', 'id_homeslider_slides', 'homeslider_slides', 'id_homeslider_slides', 'homeslider'),
            array('hook_module', 'id_hook', 'hook', 'id_hook'),
            array('hook_module', 'id_module', 'module', 'id_module'),
            array('hook_module_exceptions', 'id_hook', 'hook', 'id_hook'),
            array('hook_module_exceptions', 'id_module', 'module', 'id_module'),
            array('hook_module_exceptions', 'id_shop', 'shop', 'id_shop'),
            array('image', 'id_product', 'product', 'id_product'),
            array('message', 'id_cart', 'cart', 'id_cart'),
            array('message_readed', 'id_message', 'message', 'id_message'),
            array('message_readed', 'id_employee', 'employee', 'id_employee'),
            array('module_access', 'id_profile', 'profile', 'id_profile'),
            array('module_country', 'id_module', 'module', 'id_module'),
            array('module_country', 'id_country', 'country', 'id_country'),
            array('module_country', 'id_shop', 'shop', 'id_shop'),
            array('module_currency', 'id_module', 'module', 'id_module'),
            array('module_currency', 'id_currency', 'currency', 'id_currency'),
            array('module_currency', 'id_shop', 'shop', 'id_shop'),
            array('module_group', 'id_module', 'module', 'id_module'),
            array('module_group', 'id_group', 'group', 'id_group'),
            array('module_group', 'id_shop', 'shop', 'id_shop'),
            array('module_preference', 'id_employee', 'employee', 'id_employee'),
            array('orders', 'id_shop', 'shop', 'id_shop'),
            array('orders', 'id_shop_group', 'shop_group', 'id_shop_group'),
            array('order_carrier', 'id_order', 'orders', 'id_order'),
            array('order_cart_rule', 'id_order', 'orders', 'id_order'),
            array('order_detail', 'id_order', 'orders', 'id_order'),
            array('order_detail_tax', 'id_order_detail', 'order_detail', 'id_order_detail'),
            array('order_history', 'id_order', 'orders', 'id_order'),
            array('order_invoice', 'id_order', 'orders', 'id_order'),
            array('order_invoice_payment', 'id_order', 'orders', 'id_order'),
            array('order_invoice_tax', 'id_order_invoice', 'order_invoice', 'id_order_invoice'),
            array('order_return', 'id_order', 'orders', 'id_order'),
            array('order_return_detail', 'id_order_return', 'order_return', 'id_order_return'),
            array('order_slip', 'id_order', 'orders', 'id_order'),
            array('order_slip_detail', 'id_order_slip', 'order_slip', 'id_order_slip'),
            array('pack', 'id_product_pack', 'product', 'id_product'),
            array('pack', 'id_product_item', 'product', 'id_product'),
            array('page', 'id_page_type', 'page_type', 'id_page_type'),
            array('page_viewed', 'id_shop', 'shop', 'id_shop'),
            array('page_viewed', 'id_shop_group', 'shop_group', 'id_shop_group'),
            array('page_viewed', 'id_date_range', 'date_range', 'id_date_range'),
            array('product_attachment', 'id_attachment', 'attachment', 'id_attachment'),
            array('product_attachment', 'id_product', 'product', 'id_product'),
            array('product_attribute', 'id_product', 'product', 'id_product'),
            array('product_attribute_combination', 'id_product_attribute', 'product_attribute', 'id_product_attribute'),
            array('product_attribute_combination', 'id_attribute', 'attribute', 'id_attribute'),
            array('product_attribute_image', 'id_image', 'image', 'id_image'),
            array('product_attribute_image', 'id_product_attribute', 'product_attribute', 'id_product_attribute'),
            array('product_attribute_lang', 'id_product_attribute', 'product_attribute', 'id_product_attribute'),
            array('product_attribute_lang', 'id_lang', 'lang', 'id_lang'),
            array('product_carrier', 'id_product', 'product', 'id_product'),
            array('product_carrier', 'id_shop', 'shop', 'id_shop'),
            array('product_carrier', 'id_carrier_reference', 'carrier', 'id_reference'),
            array('product_country_tax', 'id_product', 'product', 'id_product'),
            array('product_country_tax', 'id_country', 'country', 'id_country'),
            array('product_country_tax', 'id_tax', 'tax', 'id_tax'),
            array('product_download', 'id_product', 'product', 'id_product'),
            array('product_group_reduction_cache', 'id_product', 'product', 'id_product'),
            array('product_group_reduction_cache', 'id_group', 'group', 'id_group'),
            array('product_sale', 'id_product', 'product', 'id_product'),
            array('product_supplier', 'id_product', 'product', 'id_product'),
            array('product_supplier', 'id_supplier', 'supplier', 'id_supplier'),
            array('product_tag', 'id_product', 'product', 'id_product'),
            array('product_tag', 'id_tag', 'tag', 'id_tag'),
            array('range_price', 'id_carrier', 'carrier', 'id_carrier'),
            array('range_weight', 'id_carrier', 'carrier', 'id_carrier'),
            array('referrer_cache', 'id_referrer', 'referrer', 'id_referrer'),
            array('referrer_cache', 'id_connections_source', 'connections_source', 'id_connections_source'),
            array('search_index', 'id_product', 'product', 'id_product'),
            array('search_word', 'id_lang', 'lang', 'id_lang'),
            array('search_word', 'id_shop', 'shop', 'id_shop'),
            array('shipment', 'id_order', 'orders', 'id_order'),
            array('shipment_product', 'id_shipment', 'shipment', 'id_shipment'),
            array('shipment_product', 'id_order_detail', 'order_detail', 'id_order_detail'),
            array('shop_url', 'id_shop', 'shop', 'id_shop'),
            array('specific_price_priority', 'id_product', 'product', 'id_product'),
            array('stock', 'id_warehouse', 'warehouse', 'id_warehouse'),
            array('stock', 'id_product', 'product', 'id_product'),
            array('stock_available', 'id_product', 'product', 'id_product'),
            array('stock_mvt', 'id_stock', 'stock', 'id_stock'),
            array('tab_module_preference', 'id_employee', 'employee', 'id_employee'),
            array('tab_module_preference', 'id_tab', 'tab', 'id_tab'),
            array('tag_count', 'id_tag', 'tag', 'id_tag'),
            array('tax_rule', 'id_country', 'country', 'id_country'),
            array('warehouse_carrier', 'id_warehouse', 'warehouse', 'id_warehouse'),
            array('warehouse_carrier', 'id_carrier', 'carrier', 'id_carrier'),
            array('warehouse_product_location', 'id_product', 'product', 'id_product'),
            array('warehouse_product_location', 'id_warehouse', 'warehouse', 'id_warehouse')
        );
    }

    public static function getCatalogRelatedTables()
    {
        return array(
            'product',
            'product_shop',
            'product_lang',
            'accessory',
            'category_product',
            'product_tag',
            'tag',
            'tag_count',
            'image',
            'image_lang',
            'image_shop',
            'product_carrier',
            'cart_product',
            'product_attachment',
            'product_country_tax',
            'product_download',
            'product_group_reduction_cache',
            'product_sale',
            'product_supplier',
            'warehouse_product_location',
            'supply_order_detail',
            'attribute_impact',
            'attribute',
            'attribute_lang',
            'attribute_group',
            'attribute_group_lang',
            'attribute_group_shop',
            'attribute_shop',
            'product_attribute',
            'product_attribute_lang',
            'product_attribute_shop',
            'product_attribute_combination',
            'product_attribute_image',
            'manufacturer',
            'manufacturer_lang',
            'manufacturer_shop',
            'supplier',
            'supplier_lang',
            'supplier_shop',
            'customization',
            'customization_field',
            'customization_field_lang',
            'customized_data',
            'feature',
            'feature_lang',
            'feature_product',
            'feature_shop',
            'feature_value',
            'feature_value_lang',
            'pack',
            'search_index',
            'search_word',
            'alias',
            'specific_price',
            'specific_price_priority',
            'specific_price_rule',
            'specific_price_rule_condition',
            'specific_price_rule_condition_group',
            'stock',
            'stock_available',
            'stock_mvt',
            'warehouse'
        );
    }

    public static function getSalesRelatedTables()
    {
        return array(
            'customer',
            'customer_session',
            'customer_b2b',
            'business_entity_customer_b2b',
            'cart',
            'cart_product',
            'cart_cart_rule',
            'connections',
            'connections_page',
            'connections_source',
            'customer_group',
            'customer_message',
            'customer_message_sync_imap',
            'customer_thread',
            'guest',
            'mail',
            'message',
            'message_readed',
            'orders',
            'order_carrier',
            'order_cart_rule',
            'order_detail',
            'order_detail_tax',
            'order_history',
            'order_invoice',
            'order_invoice_payment',
            'order_invoice_tax',
            'order_message',
            'order_message_lang',
            'order_payment',
            'order_return',
            'order_return_detail',
            'order_slip',
            'order_slip_detail',
            'shipment',
            'shipment_product',
            'page',
            'page_type',
            'page_viewed',
            'product_sale',
            'referrer_cache',
        );
    }

    protected static function truncateCatalog($db)
    {
        $protectedCategories = self::getProtectedCategoryIds();
        if ($protectedCategories) {
            $idList = implode(',', $protectedCategories);
            foreach (array('category', 'category_lang', 'category_shop', 'category_group') as $table) {
                if (self::tableExists($table) && self::columnExists($table, 'id_category')) {
                    self::runQuery($db, 'DELETE FROM `'._DB_PREFIX_.bqSQL($table).'` WHERE id_category NOT IN ('.$idList.')');
                }
            }
            if (self::tableExists('category')) {
                self::runQuery($db, 'ALTER TABLE `'._DB_PREFIX_.'category` AUTO_INCREMENT = '.(1 + max($protectedCategories)));
            }
        }

        $keptCategoryImages = array();
        foreach ($protectedCategories as $categoryId) {
            $keptCategoryImages[(int) $categoryId] = true;
        }
        self::deleteImagesInDirectory(defined('_PS_CAT_IMG_DIR_') ? _PS_CAT_IMG_DIR_ : '', $keptCategoryImages);

        foreach (self::getCatalogRelatedTables() as $table) {
            self::truncateTable($db, $table);
        }

        self::deleteAddressesByColumns($db, array('id_manufacturer', 'id_supplier', 'id_warehouse'));
        self::deleteProductImages();
        self::deleteImagesInDirectory(defined('_PS_MANU_IMG_DIR_') ? _PS_MANU_IMG_DIR_ : '');
        self::deleteImagesInDirectory(defined('_PS_SUPP_IMG_DIR_') ? _PS_SUPP_IMG_DIR_ : '');

        if (method_exists('Category', 'regenerateEntireNtree')) {
            try {
                Category::regenerateEntireNtree();
            } catch (Exception $e) {
            }
        }
    }

    protected static function truncateSales($db)
    {
        $tables = self::getSalesRelatedTables();
        $moduleTables = array(
            'sekeywords' => array('sekeyword'),
            'pagesnotfound' => array('pagenotfound'),
            'paypal' => array('paypal_customer', 'paypal_order')
        );

        foreach ($moduleTables as $name => $tablesForModule) {
            if (Module::isInstalled($name)) {
                $tables = array_merge($tables, $tablesForModule);
            }
        }

        foreach ($tables as $table) {
            self::truncateTable($db, $table);
        }

        self::deleteAddressesByColumns($db, array('id_customer'));
        self::resetEmployeeCounters($db);
    }

    protected static function getProtectedCategoryIds()
    {
        $home = Configuration::getMultiShopValues('PS_HOME_CATEGORY');
        $root = Configuration::getMultiShopValues('PS_ROOT_CATEGORY');
        if (!is_array($home)) {
            $home = array();
        }
        if (!is_array($root)) {
            $root = array();
        }

        $ids = array();
        foreach (array_merge($home, $root) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    protected static function deleteAddressesByColumns($db, $columns)
    {
        if (!self::tableExists('address')) {
            return;
        }

        $conditions = array();
        foreach ($columns as $column) {
            if (self::columnExists('address', $column)) {
                $conditions[] = '`'.bqSQL($column).'` > 0';
            }
        }
        if (!$conditions) {
            return;
        }

        self::runQuery($db, 'DELETE FROM `'._DB_PREFIX_.'address` WHERE '.implode(' OR ', $conditions));
    }

    protected static function resetEmployeeCounters($db)
    {
        if (!self::tableExists('employee')) {
            return;
        }

        $assignments = array();
        foreach (array('id_last_order', 'id_last_customer_message', 'id_last_customer') as $column) {
            if (self::columnExists('employee', $column)) {
                $assignments[] = '`'.bqSQL($column).'` = 0';
            }
        }
        if (!$assignments) {
            return;
        }

        self::runQuery($db, 'UPDATE `'._DB_PREFIX_.'employee` SET '.implode(', ', $assignments));
    }

    protected static function truncateTable($db, $table)
    {
        if (!self::tableExists($table)) {
            return;
        }

        self::runQuery($db, 'TRUNCATE TABLE `'._DB_PREFIX_.bqSQL($table).'`');
    }

    protected static function deleteProductImages()
    {
        $directory = self::getProductImageDirectory();
        if ($directory === '') {
            return;
        }

        if (is_dir($directory) && class_exists('Image') && method_exists('Image', 'deleteAllImages')) {
            foreach (self::getImageFormats() as $format) {
                try {
                    Image::deleteAllImages($directory, $format);
                } catch (Exception $e) {
                }
            }
        }

        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }
    }

    protected static function deleteImagesInDirectory($directory, $keepIds = array())
    {
        if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        $formats = array();
        foreach (self::getImageFormats() as $format) {
            $formats[] = preg_quote((string) $format, '/');
        }
        if (!$formats) {
            return;
        }
        $pattern = '/^([0-9]+)(-.*)?\.('.implode('|', $formats).')$/i';

        foreach ($entries as $entry) {
            if (!preg_match($pattern, $entry, $matches)) {
                continue;
            }
            if (isset($keepIds[(int) $matches[1]])) {
                continue;
            }
            $path = $directory.$entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    protected static function getProductImageDirectory()
    {
        if (defined('_PS_PROD_IMG_DIR_')) {
            return _PS_PROD_IMG_DIR_;
        }
        if (defined('_PS_PRODUCT_IMG_DIR_')) {
            return _PS_PRODUCT_IMG_DIR_;
        }
        if (defined('_PS_IMG_DIR_')) {
            return _PS_IMG_DIR_.'p/';
        }

        return '';
    }

    protected static function getImageFormats()
    {
        if (class_exists('ImageFormatConfiguration')) {
            try {
                $reflection = new ReflectionClass('ImageFormatConfiguration');
                if ($reflection->hasConstant('SUPPORTED_FORMATS')) {
                    $formats = $reflection->getConstant('SUPPORTED_FORMATS');
                    if (is_array($formats) && $formats) {
                        return array_values($formats);
                    }
                }
            } catch (Exception $e) {
            }
        }

        return array('jpg', 'jpeg', 'png', 'webp', 'avif');
    }

    protected static function cleanSuffixedTables($db, $suffix, $foreignColumn, $foreignTable, &$logs, $skipTables)
    {
        $prefix = str_replace('"', '', _DB_PREFIX_);
        $escapedPrefix = str_replace(array('%', '_'), array('\\\\%', '\\\\_'), $prefix);
        $escapedSuffix = str_replace(array('%', '_'), array('\\\\%', '\\\\_'), $suffix);
        $like = $escapedPrefix.'%'.$escapedSuffix;
        $rows = self::fetchRows('SHOW TABLES LIKE "'.$like.'"');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $associationTable = current($row);
            if (!is_string($associationTable) || $associationTable === '' || in_array($associationTable, $skipTables, true)) {
                continue;
            }
            if (strlen($associationTable) <= strlen($suffix) || substr($associationTable, -strlen($suffix)) !== $suffix) {
                continue;
            }

            $baseTable = substr($associationTable, 0, -strlen($suffix));
            $shortAssociation = self::stripPrefix($associationTable);
            $shortBase = self::stripPrefix($baseTable);
            $idColumn = 'id_'.$shortBase;

            if (!self::tableExists($shortBase) || !self::columnExists($shortAssociation, $idColumn) || !self::columnExists($shortBase, $idColumn)) {
                continue;
            }

            $query = 'DELETE FROM `'.bqSQL($associationTable).'` WHERE `'.bqSQL($idColumn).'` NOT IN (SELECT `'.bqSQL($idColumn).'` FROM `'.bqSQL($baseTable).'`)';
            self::runQuery($db, $query, $logs);

            if (self::columnExists($shortAssociation, $foreignColumn) && self::tableExists($foreignTable) && self::columnExists($foreignTable, $foreignColumn)) {
                $query = 'DELETE FROM `'.bqSQL($associationTable).'` WHERE `'.bqSQL($foreignColumn).'` NOT IN (SELECT `'.bqSQL($foreignColumn).'` FROM `'._DB_PREFIX_.bqSQL($foreignTable).'`)';
                self::runQuery($db, $query, $logs);
            }
        }
    }

    protected function getFormLanguages()
    {
        if (isset($this->context->controller) && is_object($this->context->controller) && method_exists($this->context->controller, 'getLanguages')) {
            $languages = $this->context->controller->getLanguages();
            if (is_array($languages)) {
                return $languages;
            }
        }

        if (method_exists('Language', 'getLanguages')) {
            $languages = Language::getLanguages(false);
            if (is_array($languages)) {
                return $languages;
            }
        }

        return array();
    }

    protected static function hashKey($value)
    {
        if (method_exists('Tools', 'hash')) {
            return Tools::hash($value);
        }
        if (method_exists('Tools', 'encrypt')) {
            return Tools::encrypt($value);
        }

        return md5((defined('_COOKIE_KEY_') ? _COOKIE_KEY_ : '').$value);
    }

    protected static function tableExists($table)
    {
        $tables = self::getTableMap();

        return isset($tables[strtolower(_DB_PREFIX_.$table)]);
    }

    protected static function columnExists($table, $column)
    {
        $columns = self::getColumnMap($table);

        return isset($columns[strtolower($column)]);
    }

    protected static function getTableMap()
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }

        $tables = array();
        foreach (self::fetchRows('SHOW TABLES') as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = current($row);
            if (!is_string($name) || $name === '') {
                continue;
            }
            $tables[strtolower($name)] = true;
        }

        return $tables;
    }

    protected static function getColumnMap($table)
    {
        static $cache = array();
        $key = strtolower($table);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cache[$key] = array();
        if (!self::tableExists($table)) {
            return $cache[$key];
        }

        foreach (self::fetchRows('SHOW COLUMNS FROM `'._DB_PREFIX_.bqSQL($table).'`') as $row) {
            if (isset($row['Field'])) {
                $cache[$key][strtolower($row['Field'])] = true;
            }
        }

        return $cache[$key];
    }

    protected static function fetchRows($query)
    {
        try {
            $rows = Db::getInstance()->executeS($query);
        } catch (Exception $e) {
            return array();
        }

        return is_array($rows) ? $rows : array();
    }

    protected static function runQuery($db, $query, &$logs = null)
    {
        try {
            $result = $db->execute($query);
        } catch (Exception $e) {
            return false;
        }

        if ($result && is_array($logs)) {
            $affectedRows = (int) $db->Affected_Rows();
            if ($affectedRows > 0) {
                $logs[$query] = $affectedRows;
            }
        }

        return (bool) $result;
    }

    protected static function stripPrefix($table)
    {
        if (strpos($table, _DB_PREFIX_) === 0) {
            return substr($table, strlen(_DB_PREFIX_));
        }

        return $table;
    }
}
