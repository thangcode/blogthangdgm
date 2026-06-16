<?php
/**
 * SEO Helper Class for FPTSTORE
 * Handles all SEO-related functionality including meta tags, Open Graph, 
 * Twitter Cards, Structured Data (JSON-LD), and Canonical URLs
 * 
 * @package FPTSTORE
 * @version 1.0
 */

class SEO
{
    private $site_name = '';
    private $site_url = '';
    private $title = '';
    private $description = '';
    private $keywords = '';
    private $canonical = '';
    private $og_image = '';
    private $og_type = 'website';
    private $locale = 'vi_VN';
    private $breadcrumbs = [];
    private $structured_data = [];
    private $separator = ' | ';
    private $robots = 'index, follow';

    /**
     * Initialize SEO with site defaults
     */
    public function __construct($site_name = '', $site_url = '')
    {
        $this->site_name = $site_name ?: (defined('SITE_NAME') ? SITE_NAME : 'Thắng Digital Marketing');
        $this->site_url = $site_url ?: (defined('BASE_URL') ? BASE_URL : '');
        $this->canonical = $this->getCurrentUrl();

        // Load separator from settings
        if (function_exists('get_setting')) {
            $this->separator = get_setting('seo_title_separator', ' | ');
            $this->setRobots((int) get_setting('seo_global_robots_index', 1));
            $default_og = get_setting('seo_default_og_image', '');
            if ($default_og) {
                $this->og_image = $this->site_url . $default_og;
            }
        }
    }

    /**
     * Set page title
     */
    public function setTitle($title, $append_site_name = true)
    {
        if (!empty($title)) {
            // Tránh lặp thương hiệu: nếu tiêu đề đã chứa sẵn site_name thì KHÔNG nối thêm.
            if ($append_site_name && $this->site_name !== ''
                && mb_stripos($title, $this->site_name) !== false) {
                $append_site_name = false;
            }
            $this->title = $append_site_name
                ? ($title . $this->separator . $this->site_name)
                : $title;
        } else {
            $this->title = $this->site_name;
        }
        return $this;
    }

    /**
     * Set meta description
     */
    public function setDescription($description)
    {
        // Limit to 160 characters and clean up
        $this->description = $this->truncate(strip_tags($description ?? ''), 160);
        return $this;
    }

    /**
     * Set meta keywords
     */
    public function setKeywords($keywords)
    {
        $this->keywords = $keywords ?? '';
        return $this;
    }

    /**
     * Set canonical URL
     */
    public function setCanonical($url)
    {
        $this->canonical = $url;
        return $this;
    }

    /**
     * Set Open Graph image
     */
    public function setOgImage($image)
    {
        if (!empty($image)) {
            // Convert relative path to absolute URL
            if (strpos($image, 'http') !== 0) {
                $image = $this->site_url . ltrim($image, '/');
            }
            $this->og_image = $image;
        }
        return $this;
    }

    /**
     * Set Open Graph type
     */
    public function setOgType($type)
    {
        $this->og_type = $type;
        return $this;
    }

    /**
     * Set robots directive for search indexing
     *
     * @param bool|string $indexable
     * @return $this
     */
    public function setRobots($indexable = true)
    {
        if (is_bool($indexable)) {
            $this->robots = $indexable ? 'index, follow' : 'noindex, nofollow';
            return $this;
        }

        $value = strtolower(trim((string) $indexable));
        if (in_array($value, ['noindex', 'noindex, nofollow', 'noindex,follow', '0', 'false'], true)) {
            $this->robots = 'noindex, nofollow';
        } elseif (in_array($value, ['index', 'index, follow', '1', 'true'], true)) {
            $this->robots = 'index, follow';
        } else {
            $this->robots = $value ?: 'index, follow';
        }

        return $this;
    }

    /**
     * Add breadcrumb item
     */
    public function addBreadcrumb($name, $url = '')
    {
        $this->breadcrumbs[] = [
            'name' => $name,
            'url' => $url ?: $this->getCurrentUrl()
        ];
        return $this;
    }

    /**
     * Set multiple breadcrumbs at once
     */
    public function setBreadcrumbs($breadcrumbs)
    {
        $this->breadcrumbs = $breadcrumbs;
        return $this;
    }

    /**
     * Add Product structured data for affiliate listing.
     * Lưu ý compliance affiliate: site KHÔNG phải seller (Shopee mới là), KHÔNG kiểm soát
     * tồn kho và giá có thể đổi → bỏ "seller" và "availability" để tránh schema misleading.
     */
    public function setProductData($product)
    {
        $this->structured_data['product'] = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'] ?? '',
            'description' => $product['description'] ?? $this->description,
            'offers' => [
                '@type' => 'Offer',
                'price' => $product['price'] ?? 0,
                'priceCurrency' => 'VND',
                'priceValidUntil' => date('Y-m-d', time() + 86400),
                'url' => $this->canonical ?: ($this->site_url ?? '')
            ]
        ];

        if (!empty($product['brand'])) {
            $this->structured_data['product']['brand'] = [
                '@type' => 'Brand',
                'name' => $product['brand'],
            ];
        }

        // aggregateRating: chỉ thêm khi có đánh giá THẬT của site (tránh Google phạt rich result rỗng)
        $rating_count = (int) ($product['rating_count'] ?? 0);
        $rating_value = (float) ($product['rating_value'] ?? 0);
        if ($rating_count > 0 && $rating_value > 0) {
            $this->structured_data['product']['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => round($rating_value, 1),
                'reviewCount' => $rating_count,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        if (!empty($product['image'])) {
            $this->structured_data['product']['image'] = $this->absoluteUrl($product['image']);
        }

        $this->og_type = 'product';
        return $this;
    }

    /**
     * FAQPage structured data (rất tốt cho GEO/AI answer engines).
     * @param array $faqs mảng [['question'=>..,'answer'=>..], ...]
     */
    public function setFaqData($faqs)
    {
        $entities = [];
        foreach ((array) $faqs as $f) {
            $q = trim((string) ($f['question'] ?? ''));
            $a = trim(strip_tags((string) ($f['answer'] ?? '')));
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $a,
                ],
            ];
        }
        if (empty($entities)) {
            return $this;
        }
        $this->structured_data['faq'] = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
        return $this;
    }

    /**
     * WebSite + SearchAction (sitelinks search box) — dùng cho trang chủ.
     */
    public function setWebsiteData($searchUrlTemplate = '')
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->site_name,
            'url' => $this->site_url,
        ];
        if ($searchUrlTemplate !== '') {
            $data['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $searchUrlTemplate,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }
        $this->structured_data['website'] = $data;
        return $this;
    }

    /**
     * ItemList structured data (danh sách sản phẩm trên trang danh mục).
     * @param array $items mảng URL tuyệt đối (hoặc [name,url])
     */
    public function setItemListData($items)
    {
        $elements = [];
        $pos = 1;
        foreach ((array) $items as $it) {
            $url = is_array($it) ? ($it['url'] ?? '') : (string) $it;
            if ($url === '') continue;
            $el = ['@type' => 'ListItem', 'position' => $pos, 'url' => $url];
            if (is_array($it) && !empty($it['name'])) {
                $el['name'] = $it['name'];
            }
            $elements[] = $el;
            $pos++;
        }
        if (empty($elements)) {
            return $this;
        }
        $this->structured_data['itemlist'] = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $elements,
        ];
        return $this;
    }

    /**
     * Add Article structured data for posts
     */
    public function setArticleData($article)
    {
        $type = $article['type'] ?? 'Article';
        if (!in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
            $type = 'Article';
        }
        $authorName = trim((string) ($article['author'] ?? ''));
        $author = $authorName !== ''
            ? ['@type' => 'Person', 'name' => $authorName]
            : ['@type' => 'Organization', 'name' => $this->site_name];

        $this->structured_data['article'] = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'headline' => $article['title'] ?? $this->title,
            'description' => $article['summary'] ?? $this->description,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $this->canonical ?: $this->site_url,
            ],
            'author' => $author,
            'publisher' => [
                '@type' => 'Organization',
                'name' => $this->site_name,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $this->site_url . 'assets/images/logo.png'
                ]
            ],
            'datePublished' => $article['published_date'] ?? date('c'),
            'dateModified' => $article['modified_date'] ?? ($article['published_date'] ?? date('c'))
        ];

        if (!empty($article['image'])) {
            $this->structured_data['article']['image'] = $this->absoluteUrl($article['image']);
        }

        $this->og_type = 'article';
        return $this;
    }

    /**
     * VideoObject structured data — cho bài có video YouTube.
     * Tốt cho SEO video (Google Video) và GEO/AI answer engines.
     * @param array $video ['name','description','thumbnail','upload_date','embed_url','content_url']
     */
    public function setVideoData($video)
    {
        $name = trim((string) ($video['name'] ?? $this->title));
        $thumb = trim((string) ($video['thumbnail'] ?? ''));
        if ($name === '' || $thumb === '') {
            return $this;
        }
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $name,
            'description' => (trim((string) ($video['description'] ?? '')) ?: ($this->description ?: $name)),
            'thumbnailUrl' => [strpos($thumb, 'http') === 0 ? $thumb : $this->absoluteUrl($thumb)],
            'uploadDate' => $video['upload_date'] ?? date('c'),
        ];
        if (!empty($video['embed_url'])) {
            $data['embedUrl'] = $video['embed_url'];
        }
        if (!empty($video['content_url'])) {
            $data['contentUrl'] = $video['content_url'];
        }
        $this->structured_data['video'] = $data;
        return $this;
    }

    /**
     * Add Organization structured data (for homepage)
     */
    public function setOrganizationData($org = [])
    {
        $this->structured_data['organization'] = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $org['name'] ?? $this->site_name,
            'url' => $org['url'] ?? $this->site_url,
            'logo' => $org['logo'] ?? $this->site_url . 'assets/images/logo.png',
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'telephone' => $org['phone'] ?? '',
                'contactType' => 'customer service',
                'areaServed' => 'VN',
                'availableLanguage' => 'Vietnamese'
            ]
        ];
        return $this;
    }

    /**
     * Render all SEO meta tags
     */
    public function render()
    {
        $html = '';

        // Basic meta tags
        $html .= $this->renderBasicMeta();

        // Open Graph tags
        $html .= $this->renderOpenGraph();

        // Twitter Card tags
        $html .= $this->renderTwitterCard();

        // Canonical URL
        $html .= $this->renderCanonical();

        // Structured Data (JSON-LD)
        $html .= $this->renderStructuredData();

        return $html;
    }

    /**
     * Render title tag only
     */
    public function renderTitle()
    {
        return '<title>' . $this->escape($this->title) . '</title>' . "\n";
    }

    /**
     * Render basic meta tags
     */
    private function renderBasicMeta()
    {
        $html = '';

        // Title
        $html .= '    <title>' . $this->escape($this->title) . '</title>' . "\n";

        // Description
        if (!empty($this->description)) {
            $html .= '    <meta name="description" content="' . $this->escape($this->description) . '">' . "\n";
        }

        // Keywords
        if (!empty($this->keywords)) {
            $html .= '    <meta name="keywords" content="' . $this->escape($this->keywords) . '">' . "\n";
        }

        // Robots
        $html .= '    <meta name="robots" content="' . $this->escape($this->robots) . '">' . "\n";

        return $html;
    }

    /**
     * Render Open Graph meta tags
     */
    private function renderOpenGraph()
    {
        $html = "\n    <!-- Open Graph / Facebook -->\n";
        $html .= '    <meta property="og:type" content="' . $this->escape($this->og_type) . '">' . "\n";
        $html .= '    <meta property="og:url" content="' . $this->escape($this->canonical) . '">' . "\n";
        $html .= '    <meta property="og:title" content="' . $this->escape($this->title) . '">' . "\n";
        $html .= '    <meta property="og:site_name" content="' . $this->escape($this->site_name) . '">' . "\n";
        $html .= '    <meta property="og:locale" content="' . $this->locale . '">' . "\n";

        if (!empty($this->description)) {
            $html .= '    <meta property="og:description" content="' . $this->escape($this->description) . '">' . "\n";
        }

        if (!empty($this->og_image)) {
            $html .= '    <meta property="og:image" content="' . $this->escape($this->og_image) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Render Twitter Card meta tags
     */
    private function renderTwitterCard()
    {
        $html = "\n    <!-- Twitter -->\n";
        $html .= '    <meta name="twitter:card" content="summary_large_image">' . "\n";
        $html .= '    <meta name="twitter:url" content="' . $this->escape($this->canonical) . '">' . "\n";
        $html .= '    <meta name="twitter:title" content="' . $this->escape($this->title) . '">' . "\n";

        if (!empty($this->description)) {
            $html .= '    <meta name="twitter:description" content="' . $this->escape($this->description) . '">' . "\n";
        }

        if (!empty($this->og_image)) {
            $html .= '    <meta name="twitter:image" content="' . $this->escape($this->og_image) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Render canonical URL
     */
    private function renderCanonical()
    {
        $html = "\n    <!-- Canonical -->\n";
        $html .= '    <link rel="canonical" href="' . $this->escape($this->canonical) . '">' . "\n";
        return $html;
    }

    /**
     * Render all structured data
     */
    private function renderStructuredData()
    {
        $html = '';

        // Breadcrumb structured data
        if (!empty($this->breadcrumbs)) {
            $html .= $this->renderBreadcrumbSchema();
        }

        // Other structured data (Product, Article, Organization)
        foreach ($this->structured_data as $data) {
            $html .= "\n    <script type=\"application/ld+json\">\n";
            $html .= '    ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $html .= "\n    </script>\n";
        }

        return $html;
    }

    /**
     * Render Breadcrumb structured data
     */
    private function renderBreadcrumbSchema()
    {
        $items = [];
        $position = 1;

        foreach ($this->breadcrumbs as $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb['name'],
                'item' => $crumb['url']
            ];
            $position++;
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items
        ];

        $html = "\n    <!-- Breadcrumb Schema -->\n";
        $html .= "    <script type=\"application/ld+json\">\n";
        $html .= '    ' . json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $html .= "\n    </script>\n";

        return $html;
    }

    /**
     * Get current URL
     */
    private function getCurrentUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return $protocol . '://' . $host . strtok($uri, '?');
    }

    /**
     * Convert relative URL to absolute
     */
    private function absoluteUrl($path)
    {
        if (function_exists('get_image_url')) {
            return get_image_url($path);
        }
        if (strpos($path, 'http') === 0) {
            return $path;
        }
        return $this->site_url . ltrim($path, '/');
    }

    /**
     * Escape HTML entities
     */
    private function escape($string)
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Truncate text to specified length
     */
    private function truncate($text, $length)
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length - 3) . '...';
    }
}

/**
 * Helper function to quickly get SEO for a page
 */
function get_page_seo($page_key, $pdo = null)
{
    if (!$pdo) {
        global $pdo;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM seo_settings WHERE page_key = ?");
        $stmt->execute([$page_key]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Initialize SEO object with common setup
 */
function init_seo($site_name = '', $site_url = '')
{
    return new SEO($site_name, $site_url);
}
?>
