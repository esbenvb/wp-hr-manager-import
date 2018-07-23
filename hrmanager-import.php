<?php
/**
 * Plugin Name: HR Manager Import
 * Plugin URI: https://github.com/esbenvb/wp-hr-manager-import
 * Description: Plugin for importing data from http://hr-manager.net/
 * Version: 0.1
 * Author: Esben von Buchwald
 * Author URI: https://github.com/esbenvb/
 */

function getPublishedPositions()
{
    $posts = get_posts([
        'post_type' => 'hr-position',
        'post_status' => 'publish',
        'numberposts' => -1,
    ]);
    $result = [];
    foreach ($posts as $post) {
        $originalId = get_post_meta($post->ID, 'HRManagerId', true);
        $result[$originalId] = (object) [
            'postId' => $post->ID,
        ];
    }
    return $result;
}

function updatePositions($customerAlias)
{
    $url = "https://recruiter-api.hr-manager.net/jobportal.svc/$customerAlias/positionlist/json/?incads=1&useutc=1";
    $jsonResult = file_get_contents($url);
    if (!$jsonResult) {
        return new WP_Error('rest_cannot_get_data', 'Make sure the Customer Alias is correct', ['status' => 500]);
    }
    $decodedResult = json_decode($jsonResult);
    if ($decodedResult === null) {
        return new WP_Error('rest_cannot_decode_data', '', ['status' => 500]);
    }
    $positions = $decodedResult->Items;

    $status = (object) [
        'updated' => 0,
        'deleted' => 0,
        'created' => 0,
    ];
    $existingPosts = getPublishedPositions();

    // add or update app positions in the feed
    foreach ($positions as $position) {
        $post = [
            'post_title' => $position->Name,
            'post_type' => 'hr-position',
            'post_status' => 'publish',
            'post_content' => $position->Advertisements[0]->Content ?? '',
        ];
        if (!empty($existingPosts[$position->Id])) {
            $post['ID'] = $existingPosts[$position->Id]->postId;
            unset($existingPosts[$position->Id]);
        }

        if ($id = wp_insert_post($post, true)) {
            if (empty($post['ID'])) {
                $status->created++;
            } else {
                $status->updated++;
            }
            foreach (metaboxes() as $boxId => $metabox) {
                $value = ($metabox->importValue)($position);
                $value && add_post_meta($id, $boxId, $value);
            }
        }
    }
    // remove all posts not in the feed
    foreach ($existingPosts as $existingPost) {
        if (wp_delete_post($existingPost->postId)) {
            $status->deleted++;
        }
    }
    return $status;
}

function webhook(WP_REST_Request $request)
{
    getPublishedPositions();
    $customerAlias = get_option('hrmanager_customer_alias');
    $webhookSecret = get_option('hrmanager_webhook_secret');
    $json = $request->get_json_params();
    $eventType = $json['Message']['EventType'];
    $receivedWebhookSecret = $json['WebhookSecret'] ?? '';

    // No customer alias has been set
    if (empty($customerAlias)) {
        return new WP_Error('rest_invalid_customer_alias', __('No customer alias has been set up.'), ['status' => 500]);
    }
    // wrong webhook secret provided
    if (!empty($webhookSecret) && $webhookSecret != $receivedWebhookSecret) {
        return new WP_Error('rest_invalid_secret_key', __('You must specify a valid WebhookSecret value in the call.'), ['status' => 401]);
    }

    switch ($eventType) {
        case 'PositionPublished':
        case 'PositionUnpublished':
        case 'AdvertisementUpdated':
        case 'AdvertisementPublished':
        case 'AdvertisementUnPublished':
        case 'ProjectCreated':
        case 'ProjectUpdated':
        case 'ProjectUpdated':
        case 'ProjectDeleted':
        case 'ProjectDeactivated':
            return updatePositions($customerAlias);

        default:
            return false;
    }
}

add_action('rest_api_init', function () {

    register_rest_route('hr-manager/v1', 'webhook', [
        'methods' => 'POST',
        'callback' => 'webhook',
    ]);

});

add_action('init', function () {
    register_post_type('hr-position',
        [
            'labels' => [
                'name' => __('HR positions'),
                'singular_name' => __('HR Position'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => ['slug' => get_option('hrmanager_slug')],
        ]
    );
}, 5, 1);

function render_metabox($post, $metabox)
{
    $value = get_post_meta($post->ID, $metabox['id'], true);
    print $value;
}

function metaboxes()
{
    return [

        'HRManagerId' => (object) [
            'title' => __('HR Manager ID'),
            'importValue' => function ($item) {
                return $item->Id ?? null;
            },
        ],
        'WorkPlace' => (object) [
            'title' => __('Work place'),
            'importValue' => function ($item) {
                return $item->WorkPlace ?? null;
            },
        ],
        'ProjectLeaderName' => (object) [
            'title' => __('Project leader'),
            'importValue' => function ($item) {
                return $item->ProjectLeader ?? null;
            },
        ],
        'ProjectLeaderEmail' => (object) [
            'title' => __('Project leader email'),
            'importValue' => function ($item) {
                return $item->ProjectLeaderEmail ?? null;
            },
        ],
        'ProjectLeaderPhone' => (object) [
            'title' => __('Project leader phone'),
            'importValue' => function ($item) {
                return $item->ProjectLeader->Phone ?? null;

            },
        ],
        'Location' => (object) [
            'title' => __('Location'),
            'importValue' => function ($item) {
                return $item->PositionLocation->Name ?? null;
            },
        ],
        'Category' => (object) [
            'title' => __('Category'),
            'importValue' => function ($item) {
                return $item->PositionCategory->Name ?? null;
            },
        ],
        'ApplicationFormUrl' => (object) [
            'title' => __('Application form URL'),
            'importValue' => function ($item) {
                return $item->ApplicationFormUrl ?? null;
            },
        ],
        'ApplicationDue' => (object) [
            'title' => __('Application due'),
            'importValue' => function ($item) {
                $match = preg_match("/\/Date\(([0-9]+)\)\//", $item->ApplicationDue, $output_array);
                if (!$match) {
                    return null;
                }
                return $output_array[1] / 1000;
            },
        ],
        'ImageUrl' => (object) [
            'title' => __('Image URL'),
            'importValue' => function ($item) {
                return $item->Advertisements[0]->ImageUrl ?? null;
            },
        ],
    ];
}

add_action('add_meta_boxes_hr-position', function ($post) {
    foreach (metaboxes() as $boxId => $metabox) {
        add_meta_box(
            $boxId,
            $metabox->title,
            'render_metabox',
            'hr-position',
            'normal',
            'default'
        );
    }
}, 10, 1);

function sanitize($value)
{
    return trim($value);
}

function hrmanager_options_page()
{
    ?>
    <h1>HR Manager settings</h1>

    <div class="wrap">
    <form action="options.php" method="post">
      <?php
settings_fields('hr-manager_options_group');
    do_settings_sections('hr-manager_options_group');
    ?>
       <fieldset>
          <legend>Customer alias</legend>
          <input type="text" name="hrmanager_customer_alias" value="<?php echo esc_attr(get_option('hrmanager_customer_alias')); ?>" />
          <p>Use the customer alias of the HR account.</p>
        </fieldset>
        <fieldset>
          <legend>Webhook secret</legend>
          <input type="text" name="hrmanager_webhook_secret" value="<?php echo esc_attr(get_option('hrmanager_webhook_secret')); ?>" />
          <p>The shared secret used to invoke the webhook. The caller should include a field named WebhookSecret in the JSON, with the value set here.</p>
        </fieldset>
        <fieldset>
          <legend>URL slug</legend>
          <input type="text" name="hrmanager_slug" value="<?php echo esc_attr(get_option('hrmanager_slug')); ?>" />
          <p>The part first of the URL path for this post type.</p>
        </fieldset>
        <h3>Call importer</h3>

            <p>You can trigger the import by calling the webhook</p>
            <pre>

<code>curl -X POST \
<?php echo get_site_url(); ?>-json/hr-manager/v1/webhook \
  -H 'content-type: application/json' \
  -d '{
   "Message":{
      "EventType":"PositionPublished",
      "ProjectId":143568
   },
   "DepartmentId":1,
   "CustomerId":1,
   "WebhookSecret":"<?php echo esc_attr(get_option('hrmanager_webhook_secret')); ?>"
}'
</code>
            </pre>

        <?php submit_button();?></td>
     </form>
   </div>
    <?php
}

add_action('admin_init', function () {
    add_option('hrmanager_customer_alias', '');
    add_option('hrmanager_slug', 'positions');
    add_option('hrmanager_webhook_secret', '12345');
    register_setting('hr-manager_options_group', 'hrmanager_customer_alias', 'sanitize');
    register_setting('hr-manager_options_group', 'hrmanager_slug', 'sanitize');
    register_setting('hr-manager_options_group', 'hrmanager_webhook_secret', 'sanitize');
});

add_action('admin_menu', function () {
    add_options_page('HR Manager Settings', 'HR Manager', 'manage_options', 'hr-manager', 'hrmanager_options_page');
});
