<?php
/*
  Plugin Name: Modulbank Shortcode
  Description: Shortcode for Modulbank payments
  Version: 2.2
*/

if (!class_exists('FPaymentsSCForm')) {
    include(dirname(__FILE__) . '/inc/fpayments.php');
}


class FPaymentsShortcodeCallback extends AbstractFPaymentsSCCallbackHandler {
    private $plugin;
    function __construct(FPaymentsShortcode $plugin)    { $this->plugin = $plugin; }
    protected function get_fpayments_form()             { return $this->plugin->get_fpayments_form(); }
    protected function load_order($order_id)            { return $this->plugin->load_order($order_id); }
    protected function get_order_currency($order)       { return $order['currency']; }
    protected function get_order_amount($order)         { return $order['amount']; }
    protected function is_order_completed($order)       { return $order['status'] == FPaymentsShortcode::STATUS_PAID; }
    protected function mark_order_as_completed($order, array $data) {
        $order['status'] = FPaymentsShortcode::STATUS_PAID;
        $order['meta'] = $data['meta'];
        return $this->plugin->save_order($order);
    }
    protected function mark_order_as_error($order, array $data) {
        $order['status'] = FPaymentsShortcode::STATUS_ERROR;
        $order['meta'] = $data['meta'];
        return $this->plugin->save_order($order);
    }
}


class FPaymentsShortcode {
    const VERSION        = '2.2';

    const STATUS_UNKNOWN = 'unknown';
    const STATUS_PAID    = 'paid';
    const STATUS_ERROR   = 'error';

    private $order_table;
    private $order_table_format;
    private $templates_dir;
    private $invoice_protected_fields;

    private $success_url;
    private $fail_url;
    private $submit_url;
    private $callback_url;

    static function get_slug() {
        return FPaymentsSCConfig::PREFIX . '-shortcode';
    }

    static function get_group() {
        return FPaymentsSCConfig::PREFIX . '-shortcode-options2';
    }

    static function get_name() {
        return FPaymentsSCConfig::PREFIX . '-shortcode';
    }

    function __construct() {
        global $wpdb;

        $this->success_url = get_home_url() . '/?' . FPaymentsSCConfig::PREFIX . '=success';
        $this->fail_url = get_home_url() . '/?' . FPaymentsSCConfig::PREFIX . '=fail';
        $this->submit_url = get_home_url() . '/?' . FPaymentsSCConfig::PREFIX . '=submit';
        $this->callback_url = get_home_url() . '/?' . FPaymentsSCConfig::PREFIX . '=callback';

        $db_prefix = $wpdb->prefix . FPaymentsSCConfig::PREFIX . '_shortcode_';
        $this->order_table = $db_prefix . 'order';

        $this->order_table_format = array(
            '%s',  # creation_datetime
            '%s',  # amount
            '%s',  # currency
            '%s',  # description
            '%s',  # client_email
            '%s',  # client_name
            '%s',  # client_phone
            '%s',  # status
            '%d',  # testing
            '%s',  # meta
        );

        $this->invoice_protected_fields = array(
            'amount',
            'currency',
            'description',
            'fields',
            'cancel_url',
        );

        $this->templates_dir = dirname(__FILE__) . '/templates/';
        add_action('init',  array($this, 'init'));
        add_shortcode(FPaymentsSCConfig::PREFIX, array($this, 'fpayments_button'));
        if (is_admin()) {
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('plugins_loaded', array($this, 'plugins_loaded'));
            add_action('admin_init', array($this, 'admin_init'));
        }
        add_action('parse_request', array($this, 'parse_request'));
    }


    function log($msg) {

        if(!array_key_exists('HOME', $_SERVER))
            return;

        $dest = $_SERVER['HOME'] . '/' . self::get_name() . '.log';
        if (!@error_log("$msg\n", 3, $dest)) {
            error_log('[' . self::get_name() . '] ' . $msg);
        }
    }

    function plugins_loaded() {
        $this->log('plugins_loaded()');
        $key = FPaymentsSCConfig::PREFIX . '_shortcode_version';
        $version = get_site_option($key);
        if ($version != self::VERSION) {
            $this->log('Prev version: ' . $version);
            $this->create_plugin_tables();
            $this->log('Update to ' . self::VERSION);
            update_site_option($key, self::VERSION);
        }
    }

    function parse_request() {
        $current_url = $this->get_current_url();
        foreach (array(
            $this->success_url => array($this, 'success_page'),
            $this->fail_url => array($this, 'fail_page'),
            $this->submit_url  => array($this, 'submit_page'),
            $this->callback_url => array($this, 'callback_page'),
        ) as $url => $func) {
            if ($url == $current_url) {
                $this->log('call'.$url);
                call_user_func($func);
                exit();
            }
        }
    }

    private function get_additional_fields(array $atts) {
        $additional_fields = array(
            'client_amount' => array(
                'label'  => __('Сумма', 'fpayments'),
                'hidden' => true,
                'type'   => 'number',
            ),
            'client_description' => array(
                'label'  => __('Описание', 'fpayments'),
                'hidden' => true,
                'type'   => 'text',
            ),
            'client_name' => array(
                'label'  => __('ФИО', 'fpayments'),
                'hidden' => true,
                'type'   => 'text',
            ),
            'client_email' => array(
                'label'  => __('Email', 'fpayments'),
                'hidden' => true,
                'type'   => 'email',
            ),
            'client_phone' => array(
                'label'  => __('Телефон', 'fpayments'),
                'hidden' => true,
                'type'   => 'text',
            ),
        );

        foreach (preg_split("~\s*,\s*~", $atts['fields']) as $key) {
            if (array_key_exists($key, $additional_fields)) {
                $additional_fields[$key]['hidden'] = false;
            }
        }

        if (!$atts['amount']) {
            $additional_fields['client_amount']['hidden'] = false;
        }

        if (!$atts['description']) {
            $additional_fields['client_description']['hidden'] = false;
        }

        return $additional_fields;
    }

    function fpayments_button(array $atts) {
        $this->log('fpayments_button()');

        $ff = $this->get_fpayments_form();

        if (!$ff) {
            return __('FPAYMENTS ERROR', 'fpayments') . ': ' . __('plugin is not configured', 'fpayments');
        }

        $options = $this->get_options();

        $atts = shortcode_atts(array(
            'amount'      => '',
            'currency'    => 'RUB',
            'description' => '',
            'fields'      => '',
            'button_text' => $options['pay_button_text'],
        ), $atts);

        if (!$atts['currency']) {
            return __('FPAYMENTS ERROR', 'fpayments') . ': ' . __('currency required', 'fpayments');
        }

        $atts['cancel_url'] = $this->get_current_url();

        $signed_fields = array();
        foreach ($this->invoice_protected_fields as $k) {
            $signed_fields[$k] = $atts[$k];
        }
        $atts['signature'] = $ff->get_signature($signed_fields);

        $url = $this->submit_url;
        $additional_fields = $this->get_additional_fields($atts);
        ob_start();
        include $this->templates_dir . 'fpayments_button.php';
        $result = ob_get_contents();
        ob_end_clean();
        return $result;
    }

    function init() {
        $this->log('init()');
        load_plugin_textdomain('fpayments', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    function admin_menu() {
        add_options_page(
            FPaymentsSCConfig::NAME . ' shortcode',
            FPaymentsSCConfig::NAME . ' shortcode',
            'manage_options',
            self::get_slug(),
            array($this, 'settings_page')
        );
        add_menu_page(
            __('Orders and payments', 'fpayments'),
            __('Orders and payments', 'fpayments'),
            'manage_options',
            'list',
            array($this, 'list_page'),
            '',
            6
        );
    }

    function list_page() {
        global $wpdb;
        $step = 50;
        $limit = (int) self::get($_GET, 'limit', $step);
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . $this->order_table .
            ' ORDER BY id DESC' .
            ' LIMIT ' . $limit,
            ARRAY_A
        );
        $statuses = array(
            self::STATUS_UNKNOWN => __('inprocess', 'fpayments'),
            self::STATUS_PAID    => __('paid', 'fpayments'),
            self::STATUS_ERROR   => __('error', 'fpayments'),
        );
        include $this->templates_dir . 'list.php';
    }

    function settings_page() {
        $group = self::get_group();
        $slug = self::get_slug();
        $callback_url = $this->callback_url;
        include $this->templates_dir . 'settings.php';
    }

    function admin_init() {
        register_setting(self::get_group(), self::get_group());
        add_settings_section(
            self::get_group(),
            FPaymentsSCConfig::NAME,
            array($this, 'settings_intro_text'),
            self::get_slug()
        );
        add_settings_field(
            'merchant_id',
            __('Merchant ID', 'fpayments'),
            array($this, 'char_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'merchant_id')
        );
        add_settings_field(
            'secret_key',
            __('Secret key', 'fpayments'),
            array($this, 'char_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'secret_key')
        );
        add_settings_field(
            'test_mode',
            __('Test mode', 'fpayments'),
            array($this, 'boolean_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'test_mode')
        );
        add_settings_field(
            'success_url',
            __('Success URL', 'fpayments'),
            array($this, 'char_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'success_url')
        );
        add_settings_field(
            'fail_url',
            __('Fail URL', 'fpayments'),
            array($this, 'char_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'fail_url')
        );
        add_settings_field(
            'pay_button_text',
            __('Pay button text', 'fpayments'),
            array($this, 'char_field'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'pay_button_text')
        );

        add_settings_field(
            'sno',
            __('Система налогообложения', 'fpayments'),
            array($this, 'setting_dropdown_fn'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'sno',
                  'options' => array(
                        'osn' => 'Общая',
                        'usn_income' => 'Упрощенная СН (доходы)',
                        'usn_income_outcome' => 'Упрощенная СН (доходы минус расходы)',
                        'envd' => 'Единый налог на вмененный доход',
                        'esn' => 'Единый сельскохозяйственный налог',
                        'patent' => 'Патентная СН',
                     ))
        );


        add_settings_field(
            'payment_object',
            __('Предмет расчета', 'fpayments'),
            array($this, 'setting_dropdown_fn'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'payment_object',
                'options' => array(
                    'commodity' => 'Товар',
                    'excise' => 'Подакцизный товар',
                    'job' => 'Работа',
                    'service' => 'Услуга',
                    'gambling_bet' => 'Ставка азартной игры',
                    'gambling_prize' => 'Выигрыш азартной игры',
                    'lottery' => 'Лотерейный билет',
                    'lottery_prize' => 'Выигрыш лотереи',
                    'intellectual_activity' => 'Предоставление результатов интеллектуальной деятельности',
                    'payment' => 'Платеж',
                    'agent_commission' => 'Агентское вознаграждение',
                    'composite' => 'Составной предмет расчета',
                    'another' => 'Другое'
                ))
        );

        add_settings_field(
            'payment_method',
            __('Метод платежа', 'fpayments'),
            array($this, 'setting_dropdown_fn'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'payment_method',
                'options' => array(
                    'full_prepayment' => 'Предоплата 100%',
                    'prepayment' => 'Предоплата',
                    'advance' => 'Аванс',
                    'full_payment' => 'Полный расчет',
                    'partial_payment' => 'Частичный расчет и кредит',
                    'credit' => 'Передача в кредит',
                    'credit_payment' => 'Оплата кредита'
                ))
        );

        add_settings_field(
            'vat',
            __('Ставка НДС', 'fpayments'),
            array($this, 'setting_dropdown_fn'),
            self::get_slug(),
            self::get_group(),
            array('id' => 'vat',
                'options' => array(
                    'none' => 'Без НДС',
                    'vat0' => 'НДС по ставке 0%',
                    'vat10' => 'НДС чека по ставке 10%',
                    'vat18' => 'НДС чека по ставке 18%',
                    'vat110' => 'НДС чека по расчетной ставке 10% ',
                    'vat118' => 'НДС чека по расчетной ставке 18% '
                ))
        );
    }

    function settings_intro_text() {

    }

    function char_field($args) {
        $options =  $this->get_options();
        echo '<input name="' . self::get_group() . '[' . $args['id'] . ']"' .
             ' type="text" size="40" value="' . esc_attr($options[$args['id']]) . '">';
    }

    function  setting_dropdown_fn($args) {
        $options =  $this->get_options();

        $name = self::get_group() . '[' . $args['id']. ']';
        $val = array_key_exists($args['id'],$options) ? $options[$args['id']] : '';
        $items = $args['options'];


        echo "<select id='id-$name' name='$name '>";
        foreach($items as $key => $value) {
            $selected = ($val==$key) ? 'selected="selected"' : '';
            echo "<option value='$key' $selected>$value</option>";
        }
        echo "</select>";
    }

    function boolean_field($args) {
        $options =  $this->get_options();
        $val = $options[$args['id']];
        $name = self::get_group() . '[' . $args['id']. ']';
        if($options[$args['id']]) { $checked = ' checked="checked" '; }

        echo '<input name="' . $name . '"' .
            ' type="hidden" value="0" >';
        echo '<input name="' . $name . '"' .
             ' type="checkbox" ' . $checked . '>';
    }

    function get_fpayments_form() {
        $options = $this->get_options();
        if (
            $options['merchant_id'] &&
            $options['secret_key']
        ) {
            return new FPaymentsSCForm(
                $options['merchant_id'],
                $options['secret_key'],
                $options['test_mode'],
                self::get_name() . ' ' . self::VERSION,
                "WordPress " . get_bloginfo('version')
            );
        } else {
            return false;
        }
    }

    private function get_options() {
        $result = get_option(self::get_group());
        if (!$result) {
            $result = array();
        }
        foreach (array(
            'merchant_id'     => '',
            'secret_key'      => '',
            'success_url'     => FPaymentsSCForm::abs('/success'),
            'fail_url'        => FPaymentsSCForm::abs('/fail'),
            'test_mode'       => 'on',
            'pay_button_text' => __('Оплатить картой'),
        ) as $k => $v) {
            $result[$k] = self::get($result, $k, $v);
        }
        return $result;
    }

    private function create_order(array $data) {
        $this->log('create_order(): ' . var_export($data, 1));
        $options = $this->get_options();
        $order = array(
            'creation_datetime' => current_time('mysql'),
            'amount'            => $data['amount'] ? $data['amount'] : $data['client_amount'],
            'currency'          => $data['currency'],
            'description'       => $data['description'] ? $data['description'] : $data['client_description'],
            'client_email'      => $data['client_email'],
            'client_name'       => $data['client_name'],
            'client_phone'      => $data['client_phone'],
            'status'            => self::STATUS_UNKNOWN,
            'testing'           => $options['test_mode'] ? 1 : 0,
            'meta'              => '',
        );
        global $wpdb;
        $wpdb->insert($this->order_table, $order) or die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('can\'t create order', 'fpayments'));
        $order['id'] = $wpdb->insert_id;
        $this->log('order_id = ' . $order['id']);
        return $order;
    }

    function load_order($order_id) {
        global $wpdb;
        return $wpdb->get_row('SELECT * FROM ' . $this->order_table . ' WHERE id = ' . $order_id, ARRAY_A);
    }

    function get_current_url() {
        return add_query_arg( $_SERVER['QUERY_STRING'], '', get_home_url($_SERVER['REQUEST_URI']) . '/');
    }

    function save_order(array $order) {
        $this->log('save_order(): ' . var_export($order, 1));
        global $wpdb;
        $order_id = $order['id'];
        unset($order['id']);
        $result = (bool) $wpdb->update(
            $this->order_table,
            $order,
            array('id' => $order_id),
            $this->order_table_format,
            array('%d')
        );
        $this->log('save_order result: ' . var_export($result, 1));
        return $result;
    }
    private function submit_page() {
        $ff = $this->get_fpayments_form() or
        die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('plugin is not configured', 'fpayments'));

        $h = array();
        foreach ($this->invoice_protected_fields as $k) {
            $h[$k] = self::get($_POST, $k);
        }

        if ($ff->get_signature($h) != self::get($_POST, 'signature')) {
            die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('incorrect data', 'fpayments'));
        }

        $options = $this->get_options();
        $order = $this->create_order($_POST);
        $meta = '';

        $receipt_contact = $order['client_email'] ?: $order['client_phone'] ?: '';
        $receipt_items= array(
            new FPaymentsSCRecieptItem(
                    $order['description'] ?: 'payment',
                    $order['amount'],
                    1
                    ,$options['vat']
                    ,$options['sno']
                    ,$options['payment_object']
                    ,$options['payment_method']
                ),
        );
        $data = $ff->compose(
            $order['amount'],
            $order['currency'],
            $order['id'],
            $order['client_email'],
            $order['client_name'],
            $order['client_phone'],
            $options['success_url'],
            $options['fail_url'],
            $_POST['cancel_url'],
            $this->callback_url,
            $meta,
            $order['description'],
            $receipt_contact,
            $receipt_items
        );

        include $this->templates_dir . 'submit.php';
    }


    private function success_page() {
        $ff = $this->get_fpayments_form() or
            die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('plugin is not configured', 'fpayments'));
        include $this->templates_dir . 'success.php';
    }

    private function fail_page() {
        $ff = $this->get_fpayments_form() or
            die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('plugin is not configured', 'fpayments'));
        include $this->templates_dir . 'fail.php';
    }

    private function callback_page() {
        $this->get_fpayments_form() or
            die(__('FPAYMENTS ERROR', 'fpayments') . ': ' . __('plugin is not configured', 'fpayments'));
        $cb = new FPaymentsShortcodeCallback($this);
        $cb->show($_POST);
    }

    private function create_plugin_tables() {
        $this->log('create_plugin_tables()');
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $sql = "
            CREATE TABLE " . $this->order_table . " (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                creation_datetime datetime NOT NULL,
                amount numeric(10, 2) NOT NULL,
                currency VARCHAR(3) NOT NULL,
                description longtext NOT NULL,
                client_email VARCHAR(120) NOT NULL,
                client_name VARCHAR(120) NOT NULL,
                client_phone VARCHAR(30) NOT NULL,
                status VARCHAR(30) NOT NULL default '" . self::STATUS_UNKNOWN . "',
                testing int NOT NULL default '1',
                meta longtext NOT NULL,
                UNIQUE KEY id (id)
            );
        ";
        $this->log('dbDelta(): ' . $sql);
        $result = dbDelta($sql);
        return $result;
    }

    ## helpers ##
    private static function get(array $hash, $key, $default=null) {
        if (array_key_exists($key, $hash)) {
            return $hash[$key];
        } else {
            return $default;
        }
    }
}

new FPaymentsShortcode();
