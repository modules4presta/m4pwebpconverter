<?php

declare(strict_types=1);

/**
 * LICENCE
 *
 * ALL RIGHTS RESERVED.
 * YOU ARE NOT ALLOWED TO COPY/EDIT/SHARE/WHATEVER.
 *
 * IN CASE OF ANY PROBLEM CONTACT AUTHOR.
 *
 *  @author    Jan Kołodziej (contact@modules4presta.io)
 *  @copyright modules4presta.io
 *  @license   ALL RIGHTS RESERVED
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class M4pWebpConverter extends Module
{
    public const CONFIG_QUALITY = 'M4PWEBP_QUALITY';
    public const CONFIG_MAX_PRODUCTS = 'M4PWEBP_MAX_PRODUCTS';
    public const CONFIG_FORCE_REGEN = 'M4PWEBP_FORCE_REGEN';
    public const CONFIG_ACTIVE_ONLY = 'M4PWEBP_ACTIVE_ONLY';
    public const CONFIG_THUMBS = 'M4PWEBP_THUMBS';
    public const CONFIG_SERVE_FRONT = 'M4PWEBP_SERVE_FRONT';

    /** Suffix appended to the source filename to build the WebP twin. */
    public const WEBP_SUFFIX = '-new_format.webp';

    /** Extensions we can read and that are worth re-encoding to WebP. */
    private const SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    private const AJAX_BATCH_SIZE = 10;

    /** @var Db|null */
    private $db;

    /** @var array<string, string> Local cache for Configuration::get() reads. */
    private $config = [];

    /** @var array<string, bool> Per-request cache of WebP existence checks. */
    private $webpExistsCache = [];

    /**
     * Lazily resolved DB handle — avoids opening a connection just because the
     * module was instantiated (e.g. on the module list page).
     */
    private function db(): Db
    {
        if ($this->db === null) {
            $this->db = Db::getInstance();
        }

        return $this->db;
    }

    /**
     * Returns a cached configuration value.
     * Configuration::get() has its own internal cache, but storing the result
     * here avoids even the overhead of the static call on repeated reads.
     */
    private function getCfg(string $key): string
    {
        if (!array_key_exists($key, $this->config)) {
            $this->config[$key] = (string) Configuration::get($key);
        }

        return $this->config[$key];
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public function __construct()
    {
        $this->name = 'm4pwebpconverter';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Modules4Presta.io';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('M4P WebP Converter');
        $this->description = $this->l(
            'Converts product images to WebP and serves them on the front office to cut page weight and improve Core Web Vitals.'
        );
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '9.99.99'];
    }

    public function install()
    {
        if (!function_exists('imagewebp')) {
            $this->_errors[] = $this->l(
                'PHP GD library with WebP support is required. Please enable it on your server.'
            );

            return false;
        }

        return parent::install()
            && $this->registerHook('actionWatermark')
            && $this->registerHook('actionObjectImageAddAfter')
            && $this->registerHook('actionObjectImageUpdateAfter')
            && $this->registerHook('actionAfterCreateProductImageHandler')
            && $this->registerHook('actionAfterUpdateProductImageHandler')
            && $this->registerHook('actionOutputHTMLBefore')
            && Configuration::updateValue(self::CONFIG_QUALITY, 85)
            && Configuration::updateValue(self::CONFIG_MAX_PRODUCTS, 0)
            && Configuration::updateValue(self::CONFIG_FORCE_REGEN, 0)
            && Configuration::updateValue(self::CONFIG_ACTIVE_ONLY, 0)
            && Configuration::updateValue(self::CONFIG_THUMBS, 1)
            && Configuration::updateValue(self::CONFIG_SERVE_FRONT, 1);
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName(self::CONFIG_QUALITY)
            && Configuration::deleteByName(self::CONFIG_MAX_PRODUCTS)
            && Configuration::deleteByName(self::CONFIG_FORCE_REGEN)
            && Configuration::deleteByName(self::CONFIG_ACTIVE_ONLY)
            && Configuration::deleteByName(self::CONFIG_THUMBS)
            && Configuration::deleteByName(self::CONFIG_SERVE_FRONT);
    }

    // -------------------------------------------------------------------------
    // Admin configuration page
    // -------------------------------------------------------------------------

    public function getContent()
    {
        // AJAX endpoint — must be handled before any HTML output
        if (Tools::getValue('ajax') && Tools::getValue('m4p_action') === 'convertBatch') {
            $this->ajaxConvertBatch();
        }

        $output = '';

        if (Tools::isSubmit('submitM4pWebpConfig')) {
            $quality = (int) Tools::getValue(self::CONFIG_QUALITY);
            $maxProducts = (int) Tools::getValue(self::CONFIG_MAX_PRODUCTS);
            $forceRegen = (int) (bool) Tools::getValue(self::CONFIG_FORCE_REGEN);
            $activeOnly = (int) (bool) Tools::getValue(self::CONFIG_ACTIVE_ONLY);
            $thumbs = (int) (bool) Tools::getValue(self::CONFIG_THUMBS);
            $serveFront = (int) (bool) Tools::getValue(self::CONFIG_SERVE_FRONT);

            if ($quality < 1 || $quality > 100) {
                $output .= $this->displayError($this->l('Quality must be between 1 and 100.'));
            } else {
                Configuration::updateValue(self::CONFIG_QUALITY, $quality);
                Configuration::updateValue(self::CONFIG_MAX_PRODUCTS, max(0, $maxProducts));
                Configuration::updateValue(self::CONFIG_FORCE_REGEN, $forceRegen);
                Configuration::updateValue(self::CONFIG_ACTIVE_ONLY, $activeOnly);
                Configuration::updateValue(self::CONFIG_THUMBS, $thumbs);
                Configuration::updateValue(self::CONFIG_SERVE_FRONT, $serveFront);
                $this->config = [];
                $output .= $this->displayConfirmation($this->l('Settings saved successfully.'));
            }
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin.js');

        return $output . $this->renderConfigForm() . $this->renderConvertPanel();
    }

    protected function renderConfigForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitM4pWebpConfig';
        $helper->currentIndex = $this->getAdminBaseUrl();
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Conversion Settings'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('WebP Quality (1–100)'),
                        'name' => self::CONFIG_QUALITY,
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('Quality of the output WebP image. Recommended value: 80–90.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Max products per bulk run (0 = all)'),
                        'name' => self::CONFIG_MAX_PRODUCTS,
                        'size' => 10,
                        'required' => false,
                        'desc' => $this->l(
                            'Limit how many products are processed during bulk conversion. '
                            . 'Set to 0 to process all products at once.'
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Convert thumbnails'),
                        'name' => self::CONFIG_THUMBS,
                        'desc' => $this->l(
                            'Also convert every generated thumbnail size (home_default, large_default, …), '
                            . 'not just the original file. Required for WebP to actually be used on listing pages.'
                        ),
                        'values' => [
                            ['id' => 'thumbs_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'thumbs_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Serve WebP on the front office'),
                        'name' => self::CONFIG_SERVE_FRONT,
                        'desc' => $this->l(
                            'Wraps front office <img> tags in a <picture> element offering the WebP file. '
                            . 'Browsers without WebP support keep receiving the original image.'
                        ),
                        'values' => [
                            ['id' => 'serve_front_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'serve_front_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Force regenerate'),
                        'name' => self::CONFIG_FORCE_REGEN,
                        'desc' => $this->l('When enabled, existing WebP files are overwritten during bulk conversion.'),
                        'values' => [
                            ['id' => 'force_regen_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'force_regen_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Active products only'),
                        'name' => self::CONFIG_ACTIVE_ONLY,
                        'desc' => $this->l('When enabled, only images belonging to active products are converted.'),
                        'values' => [
                            ['id' => 'active_only_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'active_only_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    protected function renderConvertPanel()
    {
        $maxProducts = (int) $this->getCfg(self::CONFIG_MAX_PRODUCTS);
        $btnLabel = $maxProducts > 0
            ? sprintf($this->l('Convert images for up to %d products'), $maxProducts)
            : $this->l('Convert images for all products');

        $ajaxUrl = $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
            'ajax' => 1,
            'm4p_action' => 'convertBatch',
        ]);

        return '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-picture"></i>&nbsp;' . $this->l('Bulk Image Conversion') . '
            </div>
            <div class="panel-body">
                <p>' . $this->l(
                    'Conversion runs directly in the browser — images that already have a WebP file are skipped automatically.'
                ) . '</p>

                <button type="button" id="m4p-convert-btn" class="btn btn-warning btn-lg">
                    <i class="icon-cogs"></i>&nbsp;' . $btnLabel . '
                </button>

                <div id="m4p-progress-wrap" style="display:none;margin-top:20px;">
                    <div class="progress" style="height:24px;">
                        <div id="m4p-progress-bar"
                             class="progress-bar progress-bar-striped active"
                             role="progressbar"
                             style="width:0%;line-height:24px;"
                             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            0%
                        </div>
                    </div>
                    <p id="m4p-summary" style="margin:6px 0 10px;color:#666;font-size:12px;"></p>
                    <div id="m4p-log-wrap"
                         style="max-height:320px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
                        <ul id="m4p-log" class="list-group" style="margin:0;border-radius:0;"></ul>
                    </div>
                </div>
            </div>
        </div>

        <script>
            var m4pAjaxUrl   = ' . json_encode($ajaxUrl) . ';
            var m4pBatchSize = ' . self::AJAX_BATCH_SIZE . ';
        </script>';
    }

    protected function getConfigFieldsValues()
    {
        return [
            self::CONFIG_QUALITY => (int) $this->getCfg(self::CONFIG_QUALITY),
            self::CONFIG_MAX_PRODUCTS => (int) $this->getCfg(self::CONFIG_MAX_PRODUCTS),
            self::CONFIG_FORCE_REGEN => (int) $this->getCfg(self::CONFIG_FORCE_REGEN),
            self::CONFIG_ACTIVE_ONLY => (int) $this->getCfg(self::CONFIG_ACTIVE_ONLY),
            self::CONFIG_THUMBS => (int) $this->getCfg(self::CONFIG_THUMBS),
            self::CONFIG_SERVE_FRONT => (int) $this->getCfg(self::CONFIG_SERVE_FRONT),
        ];
    }

    protected function getAdminBaseUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', false, [], [
            'configure' => $this->name,
            'tab_module' => $this->tab,
            'module_name' => $this->name,
        ]);
    }

    // -------------------------------------------------------------------------
    // AJAX batch endpoint
    // -------------------------------------------------------------------------

    /**
     * Processes one batch of images and returns JSON progress data.
     * Called when m4p_action=convertBatch is present in the request.
     * Terminates execution via die().
     */
    private function ajaxConvertBatch(): void
    {
        // getContent() is only reached through AdminModules (token-checked), but the
        // batch endpoint mutates files, so require the employee permission explicitly.
        if (!$this->context->employee || !$this->context->employee->isLoggedBack()) {
            $this->respondJson(['success' => false, 'error' => 'unauthorized'], 403);
        }

        $offset = max(0, (int) Tools::getValue('offset', 0));
        $batchSize = max(1, min(50, (int) Tools::getValue('batchSize', self::AJAX_BATCH_SIZE)));
        $maxProducts = (int) $this->getCfg(self::CONFIG_MAX_PRODUCTS);
        $quality = (int) $this->getCfg(self::CONFIG_QUALITY);
        $forceRegen = (bool) $this->getCfg(self::CONFIG_FORCE_REGEN);
        $activeOnly = (bool) $this->getCfg(self::CONFIG_ACTIVE_ONLY);
        $idLang = (int) $this->context->language->id;

        $total = $this->countImages($maxProducts, $activeOnly);
        $rows = $this->getImageBatch($maxProducts, $activeOnly, $idLang, $offset, $batchSize);

        $results = [];

        foreach ($rows as $row) {
            $idImage = (int) $row['id_image'];
            $idProduct = (int) $row['id_product'];

            $stats = $this->convertImageSet($idImage, $quality, $forceRegen);

            if ($stats['failed'] > 0) {
                PrestaShopLogger::addLog(
                    sprintf(
                        '[M4P WebP Converter] %d file(s) failed to convert for image #%d (product #%d)',
                        $stats['failed'],
                        $idImage,
                        $idProduct
                    ),
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR,
                    null,
                    'Product',
                    $idProduct
                );
            }

            if ($stats['failed'] > 0) {
                $status = 'error';
            } elseif ($stats['converted'] > 0) {
                $status = 'converted';
            } else {
                $status = 'skipped';
            }

            $results[] = [
                'id_image' => $idImage,
                'id_product' => $idProduct,
                'product_name' => $row['product_name'],
                'status' => $status,
                'file' => sprintf(
                    '%d converted, %d skipped, %d failed',
                    $stats['converted'],
                    $stats['skipped'],
                    $stats['failed']
                ),
            ];
        }

        $newOffset = $offset + count($rows);

        // An empty batch always terminates the run. Without this the client would
        // keep re-requesting the same offset forever whenever $total is larger
        // than the number of rows actually returned (e.g. rows deleted mid-run).
        $done = $rows === [] || $newOffset >= $total;

        $this->respondJson([
            'success' => true,
            'total' => $total,
            'offset' => $newOffset,
            'done' => $done,
            'results' => $results,
        ]);
    }

    /**
     * Emits a JSON response and terminates. Any buffered output (notices, other
     * modules' echoes) is discarded first so the payload stays parseable.
     *
     * @param array<string, mixed> $payload
     */
    private function respondJson(array $payload, int $statusCode = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $json = '{"success":false,"error":"encoding_failed"}';
            $statusCode = 500;
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        die($json);
    }

    /**
     * Returns total number of images to process.
     */
    private function countImages(int $maxProducts, bool $activeOnly): int
    {
        $activeJoin = $activeOnly
            ? 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = i.`id_product` AND p.`active` = 1'
            : '';

        if ($maxProducts > 0) {
            $sql = 'SELECT COUNT(i.`id_image`)
                    FROM `' . _DB_PREFIX_ . 'image` i
                    ' . $activeJoin . '
                    INNER JOIN (
                        SELECT DISTINCT i2.`id_product`
                        FROM `' . _DB_PREFIX_ . 'image` i2
                        ' . ($activeOnly ? 'INNER JOIN `' . _DB_PREFIX_ . 'product` p2 ON p2.`id_product` = i2.`id_product` AND p2.`active` = 1' : '') . '
                        ORDER BY i2.`id_product` ASC
                        LIMIT ' . $maxProducts . '
                    ) AS lim ON i.`id_product` = lim.`id_product`';
        } else {
            $sql = 'SELECT COUNT(i.`id_image`)
                    FROM `' . _DB_PREFIX_ . 'image` i
                    ' . $activeJoin;
        }

        return (int) $this->db()->getValue($sql);
    }

    /**
     * Returns a paginated batch of images with product names.
     *
     * @return array<int, array{id_image: string, id_product: string, product_name: string}>
     */
    private function getImageBatch(int $maxProducts, bool $activeOnly, int $idLang, int $offset, int $limit): array
    {
        $activeJoin = $activeOnly
            ? 'INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = i.`id_product` AND p.`active` = 1'
            : '';

        $maxProductsJoin = $maxProducts > 0
            ? 'INNER JOIN (
                SELECT DISTINCT i2.`id_product`
                FROM `' . _DB_PREFIX_ . 'image` i2
                ' . ($activeOnly ? 'INNER JOIN `' . _DB_PREFIX_ . 'product` p2 ON p2.`id_product` = i2.`id_product` AND p2.`active` = 1' : '') . '
                ORDER BY i2.`id_product` ASC
                LIMIT ' . $maxProducts . '
               ) AS lim ON i.`id_product` = lim.`id_product`'
            : '';

        $sql = 'SELECT i.`id_image`, i.`id_product`,
                       COALESCE(pl.`name`, CONCAT(\'#\', i.`id_product`)) AS product_name
                FROM `' . _DB_PREFIX_ . 'image` i
                ' . $activeJoin . '
                ' . $maxProductsJoin . '
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON pl.`id_product` = i.`id_product`
                    AND pl.`id_lang` = ' . $idLang . '
                ORDER BY i.`id_product` ASC, i.`id_image` ASC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        return $this->db()->executeS($sql) ?: [];
    }

    // -------------------------------------------------------------------------
    // Hooks — auto-convert on upload
    // -------------------------------------------------------------------------

    /**
     * Fired by PrestaShop core after image save & thumbnail generation (PS 8).
     * At this point the original file is guaranteed to be on disk.
     */
    public function hookActionWatermark(array $params): void
    {
        if (isset($params['id_image'])) {
            $this->processImageById((int) $params['id_image']);
        }
    }

    /**
     * Fired after a new Image object is created in the database.
     * NOTE: The physical file may NOT be on disk yet at this point
     * (the DB record is created before the file is moved).
     * We attempt conversion anyway — if the file is missing, processImageById
     * returns false silently and another hook (actionWatermark or CQRS) will handle it.
     */
    public function hookActionObjectImageAddAfter(array $params): void
    {
        if (isset($params['object']) && $params['object'] instanceof Image) {
            $this->processImageById((int) $params['object']->id);
        }
    }

    /**
     * Fired after an existing Image object is updated.
     */
    public function hookActionObjectImageUpdateAfter(array $params): void
    {
        if (isset($params['object']) && $params['object'] instanceof Image) {
            $this->processImageById((int) $params['object']->id);
        }
    }

    /**
     * PS 9 CQRS hook — fired after CreateProductImageHandler completes.
     * At this point the file IS on disk and thumbnails have been generated.
     */
    public function hookActionAfterCreateProductImageHandler(array $params): void
    {
        $this->processImageFromCqrsParams($params);
    }

    /**
     * PS 9 CQRS hook — fired after UpdateProductImageHandler completes.
     */
    public function hookActionAfterUpdateProductImageHandler(array $params): void
    {
        $this->processImageFromCqrsParams($params);
    }

    /**
     * Extract image ID from CQRS handler hook params and convert.
     */
    private function processImageFromCqrsParams(array $params): void
    {
        if (isset($params['id_image'])) {
            $this->processImageById((int) $params['id_image']);

            return;
        }

        if (isset($params['command']) && method_exists($params['command'], 'getImageId')) {
            $imageId = $params['command']->getImageId();
            if ($imageId !== null) {
                $this->processImageById((int) $imageId->getValue());
            }

            return;
        }

        if (isset($params['result']) && method_exists($params['result'], 'getValue')) {
            $this->processImageById((int) $params['result']->getValue());
        }
    }

    // -------------------------------------------------------------------------
    // Front office — serve WebP through <picture>
    // -------------------------------------------------------------------------

    /**
     * Rewrites the rendered front office HTML so that every <img> whose file has a
     * WebP twin on disk is wrapped in a <picture> element. Browsers that do not
     * support WebP simply ignore the <source> and load the original <img>.
     *
     * The core passes 'html' by reference, so mutating it here changes the output.
     */
    public function hookActionOutputHTMLBefore(array &$params): void
    {
        if (!isset($params['html']) || !is_string($params['html'])) {
            return;
        }

        if (!(bool) $this->getCfg(self::CONFIG_SERVE_FRONT)) {
            return;
        }

        $params['html'] = $this->injectWebpSources($params['html']);
    }

    private function injectWebpSources(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        // Stash existing <picture> blocks so we never nest one inside another —
        // themes that already ship art-directed images must be left untouched.
        $stash = [];
        $stashed = preg_replace_callback(
            '#<picture\b.*?</picture>#is',
            static function (array $m) use (&$stash): string {
                $key = '<!--m4p-picture-' . count($stash) . '-->';
                $stash[$key] = $m[0];

                return $key;
            },
            $html
        );

        if ($stashed === null) {
            return $html;
        }

        $rewritten = preg_replace_callback(
            '#<img\b[^>]*>#i',
            function (array $m): string {
                return $this->wrapImgTag($m[0]);
            },
            $stashed
        );

        if ($rewritten === null) {
            return $html;
        }

        return $stash === [] ? $rewritten : strtr($rewritten, $stash);
    }

    /**
     * Wraps a single <img> tag in <picture> when a WebP twin exists.
     * Returns the tag unchanged when it cannot be safely upgraded.
     */
    private function wrapImgTag(string $tag): string
    {
        if (!preg_match('#\ssrc\s*=\s*("|\')(.*?)\1#i', $tag, $src)) {
            return $tag;
        }

        $webpSrc = $this->toWebpUrl($src[2]);

        if ($webpSrc === null) {
            return $tag;
        }

        $sourceSet = $webpSrc;

        // A responsive image must keep its full candidate list, otherwise the
        // browser would pick the WebP source and lose every other resolution.
        if (preg_match('#\ssrcset\s*=\s*("|\')(.*?)\1#i', $tag, $srcset)) {
            $converted = $this->toWebpSrcset($srcset[2]);

            if ($converted === null) {
                return $tag;
            }

            $sourceSet = $converted;
        }

        $source = '<source type="image/webp" srcset="' . htmlspecialchars($sourceSet, ENT_QUOTES, 'UTF-8') . '"';

        if (preg_match('#\ssizes\s*=\s*("|\')(.*?)\1#i', $tag, $sizes)) {
            $source .= ' sizes="' . htmlspecialchars($sizes[2], ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<picture>' . $source . '>' . $tag . '</picture>';
    }

    /**
     * Converts a full srcset attribute. Returns null unless EVERY candidate has a
     * WebP twin — a partial list would silently downgrade some breakpoints.
     */
    private function toWebpSrcset(string $srcset): ?string
    {
        $candidates = preg_split('#\s*,\s*#', trim($srcset), -1, PREG_SPLIT_NO_EMPTY);

        if ($candidates === false || $candidates === []) {
            return null;
        }

        $converted = [];

        foreach ($candidates as $candidate) {
            $parts = preg_split('#\s+#', trim($candidate), 2);

            if ($parts === false || $parts[0] === '') {
                return null;
            }

            $webpUrl = $this->toWebpUrl($parts[0]);

            if ($webpUrl === null) {
                return null;
            }

            $converted[] = isset($parts[1]) ? $webpUrl . ' ' . $parts[1] : $webpUrl;
        }

        return implode(', ', $converted);
    }

    /**
     * Maps an image URL to its WebP twin URL, or null when there is no such file.
     *
     * Only URLs under the shop's /img/ directory are considered, and the resolved
     * filesystem path is confined to _PS_IMG_DIR_ so a crafted URL cannot probe
     * arbitrary locations.
     */
    private function toWebpUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strncmp($url, 'data:', 5) === 0) {
            return null;
        }

        $decoded = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $path = parse_url($decoded, PHP_URL_PATH);

        if (!is_string($path) || !preg_match('#\.(jpe?g|png|gif)$#i', $path)) {
            return null;
        }

        $pos = strripos($path, '/img/');

        if ($pos === false) {
            return null;
        }

        $relative = rawurldecode(substr($path, $pos + strlen('/img/')));

        if ($relative === '' || strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
            return null;
        }

        $webpFile = preg_replace('#\.[^.]+$#', '', _PS_IMG_DIR_ . $relative) . self::WEBP_SUFFIX;

        if (!$this->webpFileExists($webpFile)) {
            return null;
        }

        // Rewrite the extension on the original URL so query strings, CDN hosts and
        // protocol-relative prefixes are all preserved.
        $webpUrl = preg_replace('#\.(jpe?g|png|gif)(?=$|[?\#])#i', self::WEBP_SUFFIX, $decoded, 1);

        return $webpUrl === null ? null : $webpUrl;
    }

    private function webpFileExists(string $path): bool
    {
        if (!array_key_exists($path, $this->webpExistsCache)) {
            $this->webpExistsCache[$path] = is_file($path);
        }

        return $this->webpExistsCache[$path];
    }

    // -------------------------------------------------------------------------
    // Core conversion logic
    // -------------------------------------------------------------------------

    /**
     * Convert every file belonging to an image ID (original + thumbnails).
     */
    public function processImageById(int $idImage): bool
    {
        $quality = (int) $this->getCfg(self::CONFIG_QUALITY);
        $stats = $this->convertImageSet($idImage, $quality, false);

        return $stats['failed'] === 0 && ($stats['converted'] > 0 || $stats['skipped'] > 0);
    }

    /**
     * Convert the original file and, when enabled, every generated thumbnail.
     *
     * @return array{converted: int, skipped: int, failed: int}
     */
    private function convertImageSet(int $idImage, int $quality, bool $forceRegen): array
    {
        $stats = ['converted' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->findImageFiles($idImage) as $sourcePath) {
            $outputPath = $this->getWebpPath($sourcePath);

            if (!$forceRegen && file_exists($outputPath)) {
                ++$stats['skipped'];
                continue;
            }

            if ($this->convertToWebP($sourcePath, $quality)) {
                ++$stats['converted'];
            } else {
                ++$stats['failed'];
            }
        }

        return $stats;
    }

    /**
     * Every file on disk for a given image ID: the original plus each thumbnail
     * size PrestaShop generated for it (`1234-home_default.jpg` and friends).
     *
     * PrestaShop stores images at:
     *   img/p/<id_digits_split>/<id_image>[-<image_type>].<ext>
     * e.g. image 1234 -> img/p/1/2/3/4/1234.jpg
     *
     * @return array<int, string>
     */
    protected function findImageFiles(int $idImage): array
    {
        $folder = _PS_PRODUCT_IMG_DIR_ . Image::getImgFolderStatic($idImage);
        $withThumbs = (bool) $this->getCfg(self::CONFIG_THUMBS);
        $files = [];

        foreach (self::SOURCE_EXTENSIONS as $ext) {
            $original = $folder . $idImage . '.' . $ext;

            if (is_file($original)) {
                $files[] = $original;
            }

            if (!$withThumbs) {
                continue;
            }

            // The glob pattern requires the dash immediately after the ID, so image
            // 1234 never picks up files belonging to 12340. Already-generated
            // "-new_format.webp" files cannot match either — they are not in
            // SOURCE_EXTENSIONS.
            foreach (glob($folder . $idImage . '-*.' . $ext) ?: [] as $thumb) {
                $files[] = $thumb;
            }
        }

        return $files;
    }

    /**
     * Resolve the filesystem path of the original (non-thumbnail) product image.
     */
    protected function findOriginalImagePath(int $idImage): ?string
    {
        $folder = _PS_PRODUCT_IMG_DIR_ . Image::getImgFolderStatic($idImage);

        foreach (self::SOURCE_EXTENSIONS as $ext) {
            $path = $folder . $idImage . '.' . $ext;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Output path for a source file: same directory, filename + '-new_format.webp'
     * e.g. /img/p/1/2/3/4/1234.jpg -> /img/p/1/2/3/4/1234-new_format.webp
     */
    private function getWebpPath(string $sourcePath): string
    {
        $info = pathinfo($sourcePath);

        return $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . self::WEBP_SUFFIX;
    }

    /**
     * Convert a source image to WebP.
     */
    public function convertToWebP(string $sourcePath, int $quality = 85): bool
    {
        if (!is_file($sourcePath)) {
            return false;
        }

        // Guard against a missing/corrupted configuration value producing quality 0.
        $quality = max(1, min(100, $quality));

        $outputPath = $this->getWebpPath($sourcePath);
        $directory = dirname($sourcePath);

        if (!is_writable($directory)) {
            PrestaShopLogger::addLog(
                sprintf('[M4P WebP Converter] Directory is not writable: %s', $directory),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }

        $imageSize = getimagesize($sourcePath);

        if ($imageSize === false) {
            PrestaShopLogger::addLog(
                sprintf('[M4P WebP Converter] getimagesize() failed — not a valid image: %s', $sourcePath),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }

        $mimeToLoader = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        ];

        $imageType = $imageSize[2];

        if (!isset($mimeToLoader[$imageType])) {
            PrestaShopLogger::addLog(
                sprintf(
                    '[M4P WebP Converter] Unsupported image type (%s) for file: %s',
                    image_type_to_mime_type($imageType),
                    $sourcePath
                ),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );

            return false;
        }

        $gdError = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$gdError): bool {
            $gdError = $errstr;

            return true;
        });

        $image = $mimeToLoader[$imageType]($sourcePath);

        restore_error_handler();

        if (!$image) {
            PrestaShopLogger::addLog(
                sprintf(
                    '[M4P WebP Converter] Failed to load source image: %s (detected type: %s) — %s',
                    $sourcePath,
                    image_type_to_mime_type($imageType),
                    $gdError ?? 'unknown GD error'
                ),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            return false;
        }

        // imagewebp() cannot write palette images — GIFs and 8-bit PNGs must be
        // promoted to truecolor first, otherwise the call fails outright.
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        // Alpha must be preserved with blending DISABLED: with blending on, GD
        // composites incoming pixels and the saved image loses transparency.
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF || $imageType === IMAGETYPE_WEBP) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $result = imagewebp($image, $outputPath, $quality);
        imagedestroy($image);

        if (!$result) {
            // A failed write can leave a truncated file behind, which would then be
            // treated as "already converted" on the next run — remove it.
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }

            PrestaShopLogger::addLog(
                sprintf('[M4P WebP Converter] imagewebp() failed writing to: %s', $outputPath),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
        }

        return $result;
    }
}
