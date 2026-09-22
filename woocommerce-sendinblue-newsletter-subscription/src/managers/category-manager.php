<?php


namespace SendinblueWoocommerce\Managers;

use SendinblueWoocommerce\Managers\ApiManager;
use SendinblueWoocommerce\Clients\SendinblueClient;

require_once SENDINBLUE_WC_ROOT_PATH . '/src/managers/api-manager.php';
require_once SENDINBLUE_WC_ROOT_PATH . '/src/clients/sendinblue-client.php';
require_once SENDINBLUE_WC_ROOT_PATH . '/src/managers/logging-manager.php';

/**
 * Class CategoryManager
 *
 * @package SendinblueWoocommerce\Managers
 */
class CategoryManager
{
    private $api_manager;

    public const CAT_TAXONOMY_KEY = 'product_cat';

    private const CATEGORY_DEFAULT_KEYS = [
        'name',
        'slug',
        'description',
        'parent'
    ];
  
    function __construct()
    {
        $this->api_manager = new ApiManager();
    }

    private function category_sync_enabled()
    {
        $settings = $this->api_manager->get_settings();

        return empty($settings[SendinblueClient::IS_CATEGORY_SYNC_ENABLED]) ? false : true;
    }

    public function is_valid_action($term_id, $taxonomy = '')
    {
        if (self::CAT_TAXONOMY_KEY === $taxonomy) {
            $category = get_term_by('id', $term_id, self::CAT_TAXONOMY_KEY);
            return is_object($category) ? $category : [];
        }

        return [];
    }

    public function category_deleted($term_id, $tt_id = '', $taxonomy = '', $category = '')
    {
        if (self::CAT_TAXONOMY_KEY !== $taxonomy) {
            // Fires for every taxonomy on the site, so this is normal, not a
            // fault — but at debug level it still answers "did we see it?".
            LoggingManager::instance()->debug('category', 'term delete ignored: not a product category', array(
                'taxonomy' => $taxonomy,
            ));

            return;
        }

        if (!is_object($category) || !$this->category_sync_enabled()) {
            LoggingManager::instance()->debug('category', 'category delete not sent', array(
                'term_id'      => (int) $term_id,
                'have_term'    => is_object($category),
                'sync_enabled' => $this->category_sync_enabled(),
            ));

            return;
        }

        LoggingManager::instance()->info('category', 'category deleted', array('term_id' => (int) $term_id));

        $client = new SendinblueClient();
        $client->eventsSync(SendinblueClient::CATEGORY_DELETED, $this->prepare_payload($category, true));
    }

    public function category_updated($term_id, $tt_id = '', $taxonomy = '')
    {
        $category = $this->is_valid_action($term_id, $taxonomy);
        if (empty($category) || !$this->category_sync_enabled()) {
            $this->log_category_skip('category update not sent', $term_id, $taxonomy, $category);

            return;
        }

        LoggingManager::instance()->info('category', 'category updated', array('term_id' => (int) $term_id));

        $client = new SendinblueClient();
        $client->eventsSync(SendinblueClient::CATEGORY_UPDATED, $this->prepare_payload($category));
    }

    public function category_created($term_id, $tt_id = '', $taxonomy = '')
    {
        $category = $this->is_valid_action($term_id, $taxonomy);
        if (empty($category) || !$this->category_sync_enabled()) {
            $this->log_category_skip('category create not sent', $term_id, $taxonomy, $category);

            return;
        }

        LoggingManager::instance()->info('category', 'category created', array('term_id' => (int) $term_id));

        $client = new SendinblueClient();
        $client->eventsSync(SendinblueClient::CATEGORY_CREATED, $this->prepare_payload($category));
    }

    /**
     * @param string $message
     * @param int    $term_id
     * @param string $taxonomy
     * @param mixed  $category
     * @return void
     */
    private function log_category_skip($message, $term_id, $taxonomy, $category)
    {
        LoggingManager::instance()->debug('category', $message, array(
            'term_id'      => (int) $term_id,
            'taxonomy'     => $taxonomy,
            'have_term'    => !empty($category),
            'sync_enabled' => $this->category_sync_enabled(),
        ));
    }

    public function prepare_payload($category, $is_deleted = false)
    {
        $data = [];
        $data['id'] = $category->term_id;
        foreach (self::CATEGORY_DEFAULT_KEYS as $key => $value) {
            if (!empty($category->$value)) {
                $data[$value] = $category->$value;
            }
        }

        $thumbnail_id = get_term_meta($data['id'], 'thumbnail_id', true );
        if (!empty($thumbnail_id)) {
            $image_url = wp_get_attachment_url($thumbnail_id);
            $data['image'] = (object) [
                'id' => (int) $thumbnail_id,
                'src' => is_string($image_url) ? $image_url : ""
            ];
        }

        $cat_url = get_term_link($data['id'], self::CAT_TAXONOMY_KEY);
        if (!empty($cat_url) && !$is_deleted) {
            $data['url'] = $cat_url;
        }

        return $data;
    }
}
