<?php
/**
 * Plugin Name: HR Manager Import
 * Plugin URI: http://www.mywebsite.com/my-first-plugin
 * Description: The very first plugin that I have ever created.
 * Version: 1.0
 * Author: Your Name
 * Author URI: http://www.mywebsite.com
 */

// Register the rest route here.

function webhook(WP_REST_Request $request)
{
    $customerAlias = get_option('hrmanager_customer_alias');
    $webhookSecret = get_option('hrmanager_webhook_secret');
    $json = $request->get_json_params();
    $eventType = $json['Message']['EventType'];
    $receivedWebhookSecret = $json['WebhookSecret'] ?? '';

    // No customer alias has been set
    if (empty($customerAlias)) {
        return new WP_Error('rest_invalid_customer_alias', __('No customer alias has been set up.'), array('status' => 500));
    }
    // wrong webhook secret provided
    if (!empty($webhookSecret) && $webhookSecret != $receivedWebhookSecret) {
        return new WP_Error('rest_invalid_secret_key', __('You must specify a valid WebhookSecret value in the call.'), array('status' => 401));
    }

    switch ($eventType) {
        case 'PositionPublished':
            $url = "https://recruiter-api.hr-manager.net/jobportal.svc/$customerAlias/positionlist/json/?incads=1&useutc=1";
            $jsonResult = file_get_contents($url);
            if (!$jsonResult) {
                return new WP_Error('rest_cannot_get_data', 'Make sure the Customer Alias is correct', array('status' => 500));
            }
            $decodedResult = json_decode($jsonResult);
            if ($decodedResult === null) {
                return new WP_Error('rest_cannot_decode_data', '', array('status' => 500));
            }
            $positionList = $decodedResult->Items;
            $positions = [];
            foreach ($positionList as $position) {
                $newPost = array(
                    'import_id' => $position->Id,
                    'post_title' => $position->Name,
                    'post_type' => 'hr-position',
                    'post_status' => 'publish',
                    'post_content' => $position->Advertisements[0]->Content ?? '',
                );
                $positions[] = $newPost;
                if ($id = wp_insert_post($newPost, true)) {
                    foreach (metaboxes() as $boxId => $metabox) {
                        $value = ($metabox->importValue)($position);
                        $value && add_post_meta($id, $boxId, $value);
                    }
                }
            }
            return $positions;

        case 'PositionUnpublished':
            return true;
        default:
            return false;
    }
}

add_action('rest_api_init', function () {

    register_rest_route('hr-manager/v1', 'webhook', array(

        'methods' => 'POST',
        'callback' => 'webhook',

    ));

});

add_action('init', function () {
    register_post_type('hr-position',
        array(
            'labels' => array(
                'name' => __('HR positions'),
                'singular_name' => __('HR Position'),
            ),
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'hr-positions'),
        )
    );
}, 5, 1);

function render_metabox($post, $metabox)
{
    $value = get_post_meta($post->ID, $metabox['id'], true);
    print $value;
}

function metaboxes()
{
    return array(

        'HRManagerId' => (object) array(
            'title' => __('HR Manager ID'),
            'importValue' => function ($item) {
                return $item->Id ?? null;
            },
        ),
        'WorkPlace' => (object) array(
            'title' => __('Work place'),
            'importValue' => function ($item) {
                return $item->WorkPlace ?? null;
            },
        ),
        'ProjectLeaderName' => (object) array(
            'title' => __('Project leader'),
            'importValue' => function ($item) {
                return $item->ProjectLeader ?? null;
            },
        ),
        'ProjectLeaderEmail' => (object) array(
            'title' => __('Project leader email'),
            'importValue' => function ($item) {
                return $item->ProjectLeaderEmail ?? null;
            },
        ),
        'ProjectLeaderPhone' => (object) array(
            'title' => __('Project leader phone'),
            'importValue' => function ($item) {
                return $item->ProjectLeader->Phone ?? null;

            },
        ),
        'Location' => (object) array(
            'title' => __('Location'),
            'importValue' => function ($item) {
                return $item->PositionLocation->Name ?? null;
            },
        ),
        'Category' => (object) array(
            'title' => __('Category'),
            'importValue' => function ($item) {
                return $item->PositionCategory->Name ?? null;
            },
        ),
        'ApplicationFormUrl' => (object) array(
            'title' => __('Application form URL'),
            'importValue' => function ($item) {
                return $item->ApplicationFormUrl ?? null;
            },
        ),
        'ApplicationDue' => (object) array(
            'title' => __('Application due'),
            'importValue' => function ($item) {
                $match = preg_match("/\/Date\(([0-9]+)\)\//", $item->ApplicationDue, $output_array);
                if (!$match) {
                    return null;
                }
                return $output_array[1] / 1000;
            },
            'ImageUrl' => (object) array(
                'title' => __('Image URL'),
                'importValue' => function ($item) {
                    return $item->Advertisements[0]->ImageUrl ?? null;
                },
            ),
        ),
    );
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
          <legend>Account</legend>
          <input type="text" name="hrmanager_customer_alias" value="<?php echo esc_attr(get_option('hrmanager_customer_alias')); ?>" />
          <p>Use the customer alias of the HR account.</p>
        </fieldset>
        <fieldset>
          <legend>API key</legend>
          <input type="text" name="hrmanager_apikey" value="<?php echo esc_attr(get_option('hrmanager_account')); ?>" />
          <p>The secret key used to access the API (if any).</p>
        </fieldset>
        <fieldset>
          <legend>Webhook secret</legend>
          <input type="text" name="hrmanager_webhook_secret" value="<?php echo esc_attr(get_option('hrmanager_webhook_secret')); ?>" />
          <p>The shared secret used to invoke the webhook.</p>
        </fieldset>
        <?php submit_button();?></td>
     </form>
   </div>
    <?php
}

add_action('admin_init', function () {
    add_option('hrmanager_account', '');
    add_option('hrmanager_apikey', '');
    add_option('hrmanager_webhook_secret', '');
    register_setting('hr-manager_options_group', 'hrmanager_customer_alias', 'sanitize');
    register_setting('hr-manager_options_group', 'hrmanager_apikey', 'sanitize');
    register_setting('hr-manager_options_group', 'hrmanager_webhook_secret', 'sanitize');
});

add_action('admin_menu', function () {
    add_options_page('HR Manager Settings', 'HR Manager', 'manage_options', 'hr-manager', 'hrmanager_options_page');
});
