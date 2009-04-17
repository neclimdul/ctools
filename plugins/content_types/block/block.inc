<?php
// $Id$

/**
 * @file
 * Provide Drupal blocks as content.
 *
 * Since blocks don't provide all of the features we do, we have to do a little
 * extra work, including providing icons and categories for core blocks. Blocks
 * from contrib modules get to provide their own stuff, or get relegated to
 * the old "Miscellaneous" category.
 */

/**
 * Callback function to supply a list of content types.
 */
function ctools_block_ctools_content_types() {
  return array(
    // And this is just the administrative title.
    // All our callbacks are named according to the standard pattern and can be deduced.
    'title' => t('Block'),
  );
}

/**
 * Return all block content types available.
 *
 * Modules wanting to make special adjustments the way that panels handles their blocks
 * can implement an extension to the hook_block() family, where the function name is
 * of the form "$module . '_ctools_block_info'".
 */
function ctools_block_content_type_content_types() {
  $types = array();
  foreach (module_list() as $module) {
    $module_blocks = module_invoke($module, 'block', 'list');
    if ($module_blocks) {
      foreach ($module_blocks as $delta => $block) {
        // strip_tags used because it goes through check_plain and that
        // just looks bad.
        $info = array(
          'title' => strip_tags($block['info']),
        );

        // Ask around for further information by invoking the hook_block() extension.
        $function = $module . '_ctools_block_info';
        if (!function_exists($function)) {
          $function = 'ctools_default_block_info';
        }
        $function($module, $delta, $info);

        // this check means modules can remove their blocks; particularly useful
        // if they offer the block some other way (like we do for views)
        if ($info) {
          $types["$module-$delta"] = $info;
        }
      }
    }
  }
  return $types;
}

/**
 * Output function for the 'block' content type. Outputs a block
 * based on the module and delta supplied in the configuration.
 */
function ctools_block_content_type_render($subtype, $conf) {
  list($module, $delta) = explode('-', $subtype, 2);
  $block = (object) module_invoke($module, 'block', 'view', $delta);
  if (empty($block)) {
    return;
  }

  $block->title = $block->subject;

  if (user_access('administer blocks')) {
    $block->admin_links = array(
      array(
        'title' => t('Configure block'),
        'alt' => t("Configure this block's 'block settings' in administer >> site building >> blocks"),
        'href' => "admin/build/block/configure/$module/$delta",
        'query' => drupal_get_destination(),
      ),
    );
  }

  // TEMP: Disabling block visibility checking. Ultimately we may be able to
  // finally just say it's not supported.
  return $block;

  // This seems extra but it prevents an unnecessary query sometimes.
  if (empty($conf['block_visibility']) && $block->module != 'block') {
    return $block;
  }

  // Test for block visibility

  $result = db_query("SELECT title, pages, visibility FROM {blocks} WHERE module = '%s' AND delta = '%s'", $block->module, $block->delta);
  $block_visibility = db_fetch_object($result);

  if ($block->module == 'block') {
    $block->title = $block_visibility->title;
  }

  if (empty($conf['block_visibility'])) {
    return $block;
  }

  if ($block_visibility && $block_visibility->pages) {
    if ($block_visibility->visibility < 2) {
      $path       = drupal_get_path_alias($_GET['q']);
      $regexp     = '/^('. preg_replace(array('/(\r\n?|\n)/', '/\\\\\*/', '/(^|\|)\\\\<front\\\\>($|\|)/'), array('|', '.*', '\1'. preg_quote(variable_get('site_frontpage', 'node'), '/') .'\2'), preg_quote($block_visibility->pages, '/')) .')$/';
      $page_match = !($block_visibility->visibility xor preg_match($regexp, $path));
    }
    else {
      $page_match = drupal_eval($block_visibility->pages);
    }
  }
  else {
    $page_match = TRUE;
  }

  if ($page_match) {
    return $block;
  }
}

/**
 * Empty form so we can have the default override title.
 */
function ctools_block_content_type_edit_form(&$form, &$form_state) {
  // Does nothing!
}

/**
 * The submit form stores the data in $conf.
 */
function ctools_block_content_type_edit_form_submit(&$form, &$form_state) {
  $form_state['conf'] = $form_state['values'];
}

/**
 * Returns an edit form for a block.
 */
//function ctools_block_content_type_edit_form($id, $parents, $conf) {
//  if (user_access('administer advanced pane settings')) {
//    $form['block_visibility'] = array(
//      '#type' => 'checkbox',
//      '#title' => t('Use block visibility settings (see block config)'),
//      '#default_value' => !empty($conf['block_visibility']),
//      '#description' => t('If checked, the block visibility settings for this block will apply to this block.'),
//    );
//    // Module-specific block configurations.
//    if ($settings = module_invoke($module, 'block', 'configure', $delta)) {
//      // Specifically modify a couple of core block forms.
//      if ($module == 'block') {
//        unset($settings['submit']);
//        $settings['info']['#type'] = 'value';
//        $settings['info']['#value'] = $settings['info']['#default_value'];
//      }
//      panels_admin_fix_block_tree($settings);
//      $form['block_settings'] = array(
//        '#type' => 'fieldset',
//        '#title' => t('Block settings'),
//        '#description' => t('Settings in this section are global and are for all blocks of this type, anywhere in the system.'),
//        '#tree' => FALSE,
//      );
//
//
//      $form['block_settings'] += $settings;
//    }
//  }
//
//  return $form;
//}

//function panels_admin_submit_block(&$form_values) {
//  if (!empty($form_values['block_settings'])) {
//    module_invoke($form_values['module'], 'block', 'save', $form_values['delta'], $form_values['block_settings']);
//  }
//}
//
///**
// * Because form api cannot collapse just part of a tree, and the block settings
// * assume no tree, we have to collapse the tree ourselves.
// */
//function panels_admin_fix_block_tree(&$form, $key = NULL) {
//  if ($key) {
//    if (!empty($form['#parents'])) {
//      $form['#parents'] = array_merge(array('configuration', 'block_settings'), $form['#parents']);
//    }
//    else if (empty($form['#tree'])) {
//      $form['#parents'] = array('configuration', 'block_settings', $key);
//    }
//  }
//
//  if (isset($form['#type']) && $form['#type'] == 'textarea' && !empty($form['#rows']) && $form['#rows'] > 10) {
//    $form['#rows'] = 10;
//  }
//
//  foreach (element_children($form) as $key) {
//    panels_admin_fix_block_tree($form[$key], $key);
//  }
//}

/**
 * Returns the administrative title for a type.
 */
function ctools_block_content_type_admin_title($subtype, $conf) {
  list($module, $delta) = explode('-', $subtype, 2);
  $block = module_invoke($module, 'block', 'list');
  if (empty($block) || empty($block[$delta])) {
    return t('Deleted/missing block @module-@delta', array('@module' => $module, '@delta' => $delta));
  }

  $title = filter_xss_admin($block[$delta]['info']);
  return $title;
}

/**
 * Output function for the 'block' content type. Outputs a block
 * based on the module and delta supplied in the configuration.
 */
function ctools_block_content_type_admin_info($subtype, $conf) {
  list($module, $delta) = explode('-', $subtype, 2);
  $block = (object) module_invoke($module, 'block', 'view', $delta);
  if (!empty($block) && !empty($block->subject)) {
    $block->title = $block->subject;
    return $block;
  }
}

/**
 * Provide default icon and categories for blocks when modules don't do this
 * for us.
 */
function ctools_default_block_info($module, $delta, &$info) {
  $core_modules = array('aggregator', 'block', 'blog', 'blogapi', 'book', 'color', 'comment', 'contact', 'drupal', 'filter', 'forum', 'help', 'legacy', 'locale', 'menu', 'node', 'path', 'ping', 'poll', 'profile', 'search', 'statistics', 'taxonomy', 'throttle', 'tracker', 'upload', 'user', 'watchdog', 'system');

  if (in_array($module, $core_modules)) {
    $info['icon'] = 'icon_core_block.png';
    $info['category'] = t('Miscellaneous');
  }
  else {
    $info['icon'] = 'icon_contrib_block.png';
    $info['category'] = t('Miscellaneous');
  }
}

// These are all on behalf of modules that don't implement panels but we that
// we care about.
function menu_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_block_menu.png';
  $info['category'] = t('Menus');
  if ($delta == 'primary-links' || $delta == 'secondary-links') {
    $info['icon'] = 'icon_core_primarylinks.png';
  }
}

function forum_ctools_block_info($module, $delta, &$info) {
  $info['category'] = t('Activity');
  switch ($delta) {
    case '0':
      $info['icon'] = 'icon_core_activeforumtopics.png';
      break;

    case '1':
      $info['icon'] = 'icon_core_newforumtopics.png';
      break;

    default:
      // safety net
      panels_default_block_info($module, $delta, $info);
  }
}

function profile_ctools_block_info($module, $delta, &$info) {
  // Hide the author information block which isn't as rich as what we can
  // do with context.
  $info = NULL;
}

function book_ctools_block_info($module, $delta, &$info) {
  // Hide the book navigation block which isn't as rich as what we can
  // do with context.
  $info = NULL;
}

function blog_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_recentblogposts.png';
  $info['category'] = t('Activity');
}

function poll_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_recentpoll.png';
  $info['category'] = t('Activity');
}

function comment_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_recentcomments.png';
  $info['category'] = t('Activity');
}

function search_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_searchform.png';
  $info['category'] = t('Widgets');
}

function node_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_syndicate.png';
  $info['category'] = t('Widgets');
}

function aggregator_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_syndicate.png';
  $info['category'] = t('Feeds');
}

function block_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_block_empty.png';
  $info['category'] = t('Miscellaneous');
}

function user_ctools_block_info($module, $delta, &$info) {
  $info['category'] = t('Activity');
  switch ($delta) {
    case '0':
      $info['icon'] = 'icon_core_userlogin.png';
      $info['category'] = t('Widgets');
      break;

    case '1':
      $info['icon'] = 'icon_core_navigation.png';
      $info['category'] = t('Menus');
      break;

    case '2':
      $info['icon'] = 'icon_core_whosnew.png';
      break;

    case '3':
      $info['icon'] = 'icon_core_whosonline.png';
      break;

    default:
      // safety net
      ctools_default_block_info($module, $delta, $info);
  }
}

function locale_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_languageswitcher.png';
  $info['category'] = t('Widgets');
}

function statistics_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_popularcontent.png';
  $info['category'] = t('Activity');
}

function system_ctools_block_info($module, $delta, &$info) {
  $info['icon'] = 'icon_core_drupal.png';
  $info['category'] = t('Widgets');
}