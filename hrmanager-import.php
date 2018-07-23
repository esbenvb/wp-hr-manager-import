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
    $json = $request->get_json_params();
    $eventType = $json['Message']['EventType'];
    switch ($eventType) {
        case 'PositionPublished':
            $url = 'https://recruiter-api.hr-manager.net/jobportal.svc/jp-politiken/positionlist/json/';
            $positionList = json_decode(file_get_contents($url))->Items;
            $positions = [];
            foreach ($positionList as $position) {
                $newPost = array(
                    'import_id' => $position->Id,
                    'post_title' => $position->Name,
                    'post_type' => 'hr-position',
                    'post_status' => 'publish',
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
