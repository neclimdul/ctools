<?php
// $Id$

/**
 * Base class for export UI.
 */
class ctools_export_ui {
  var $plugin;
  var $name;
  var $options = array();

  /**
   * Fake constructor -- this is easier to deal with than the real
   * constructor because we are retaining PHP4 compatibility, which
   * would require all child classes to implement their own constructor.
   */
  function init($plugin) {
    ctools_include('export');

    $this->plugin = $plugin;
  }

  // ------------------------------------------------------------------------
  // Menu item manipulation

  /**
   * hook_menu() entry point.
   *
   * Child implementations that need to add or modify menu items should
   * probably call parent::hook_menu($items) and then modify as needed.
   */
  function hook_menu(&$items) {
    $prefix = ctools_export_ui_plugin_base_path($this->plugin);

    $my_items = array();
    foreach ($this->plugin['menu']['items'] as $item) {
      // Add menu item defaults.
      $item += array(
        'file' => 'export-ui.inc',
        'file path' => drupal_get_path('module', 'ctools') . '/includes',
      );

      $path = !empty($item['path']) ? $prefix . '/' . $item['path'] : $prefix;
      unset($item['path']);
      $my_items[$path] = $item;
    }

    $items += $my_items;
  }

  /**
   * Menu callback to determine if an operation is accessible.
   *
   * This function enforces a basic access check on the configured perm
   * string, and then additional checks as needed.
   *
   * @param $op
   *   The 'op' of the menu item, which is defined by 'allowed operations'
   *   and embedded into the arguments in the menu item.
   * @param $item
   *   If an op that works on an item, then the item object, otherwise NULL.
   *
   * @return
   *   TRUE if the current user has access, FALSE if not.
   */
  function access($op, $item) {
    if (!user_access($this->plugin['access'])) {
      return FALSE;
    }

    // If we need to do a token test, do it here.
    if (!empty($this->plugin['allowed operations'][$op]['token']) && (!isset($_GET['token']) || !drupal_valid_token($_GET['token'], $op))) {
      return FALSE;
    }

    switch ($op) {
      case 'import':
        return user_access('use PHP for block visibility');
      case 'revert':
        return ($item->export_type & EXPORT_IN_DATABASE) && ($item->export_type & EXPORT_IN_CODE);
      case 'delete':
        return ($item->export_type & EXPORT_IN_DATABASE) && !($item->export_type & EXPORT_IN_CODE);
      case 'disable':
        return empty($item->disabled);
      case 'enable':
        return !empty($item->disabled);
      default:
        return TRUE;
    }
  }

  // ------------------------------------------------------------------------
  // These methods are the API for generating the list of exportable items.

  /**
   * Master entry point for handling a list.
   *
   * It is unlikely that a child object will need to override this method,
   * unless the listing mechanism is going to be highly specialized.
   */
  function list_page($js, $input) {
    $this->items = ctools_export_crud_load_all($this->plugin['schema'], !$js);

    // Respond to a reset command by clearing session and doing a drupal goto
    // back to the base URL.
    if (isset($input['op']) && $input['op'] == t('Reset')) {
      unset($_SESSION['ctools_export_ui'][$this->plugin['name']]);
      if (!$js) {
        return drupal_goto($_GET['q']);
      }
      // clear everything but form id, form build id and form token:
      $keys = array_keys($input);
      foreach ($keys as $id) {
        if (!in_array($id, array('form_id', 'form_build_id', 'form_token'))) {
          unset($input[$id]);
        }
      }
      $replace_form = TRUE;
    }

    // If there is no input, check to see if we have stored input in the
    // session.
    if (!isset($input['form_id'])) {
      if (isset($_SESSION['ctools_export_ui'][$this->plugin['name']]) && is_array($_SESSION['ctools_export_ui'][$this->plugin['name']])) {
        $input  = $_SESSION['ctools_export_ui'][$this->plugin['name']];
      }
    }
    else {
      $_SESSION['ctools_export_ui'][$this->plugin['name']] = $input;
      unset($_SESSION['ctools_export_ui'][$this->plugin['name']]['q']);
    }

    // This is where the form will put the output.
    $this->rows = array();
    $this->sorts = array();

    $form_state = array(
      'plugin' => $this->plugin,
      'input' => $input,
      'rerender' => TRUE,
      'no_redirect' => TRUE,
      'object' => &$this,
    );

    ctools_include('form');
    $form = ctools_build_form('ctools_export_ui_list_form', $form_state);

    $output = $this->list_header($form_state) . $this->list_render($form_state) . $this->list_footer($form_state);

    if (!$js) {
      $this->list_css();
      return $form . $output;
    }

    ctools_include('ajax');
    $commands = array();
    $commands[] = ctools_ajax_command_replace('#ctools-export-ui-list-items', $output);
    if (!empty($replace_form)) {
      $commands[] = ctools_ajax_command_replace('#ctools-export-ui-list-form', $form);
    }
    ctools_ajax_render($commands);
  }

  /**
   * Create the filter/sort form at the top of a list of exports.
   *
   * This handles the very default conditions, and most lists are expected
   * to override this and call through to parent::list_form() in order to
   * get the base form and then modify it as necessary to add search
   * gadgets for custom fields.
   */
  function list_form(&$form, &$form_state) {
    // This forces the form to *always* treat as submitted which is
    // necessary to make it work.
    $form['#token'] = FALSE;
    if (empty($form_state['input'])) {
      $form["#post"] = TRUE;
    }

    // Add the 'q' in if we are not using clean URLs or it can get lost when
    // using this kind of form.
    if (!variable_get('clean_url', FALSE)) {
      $form['q'] = array(
        '#type' => 'hidden',
        '#value' => $_GET['q'],
      );
    }

    $all = array('all' => t('- All -'));

    $form['top row'] = array(
      '#prefix' => '<div class="ctools-export-ui-row ctools-export-ui-top-row clear-block">',
      '#suffix' => '</div>',
    );

    $form['bottom row'] = array(
      '#prefix' => '<div class="ctools-export-ui-row ctools-export-ui-bottom-row clear-block">',
      '#suffix' => '</div>',
    );

    $form['top row']['storage'] = array(
      '#type' => 'select',
      '#title' => t('Storage'),
      '#options' => $all + array(
        t('Normal') => t('Normal'),
        t('Default') => t('Default'),
        t('Overridden') => t('Overridden'),
      ),
      '#default_value' => 'all',
      '#attributes' => array('class' => 'ctools-auto-submit'),
    );

    $form['top row']['disabled'] = array(
      '#type' => 'select',
      '#title' => t('Enabled'),
      '#options' => $all + array(
        '0' => t('Enabled'),
        '1' => t('Disabled')
      ),
      '#default_value' => 'all',
      '#attributes' => array('class' => 'ctools-auto-submit'),
    );

    $form['top row']['search'] = array(
      '#type' => 'textfield',
      '#title' => t('Search'),
      '#attributes' => array('class' => 'ctools-auto-submit'),
    );

    $form['bottom row']['order'] = array(
      '#type' => 'select',
      '#title' => t('Sort by'),
      '#options' => $this->list_sort_options(),
      '#default_value' => 'disabled',
      '#attributes' => array('class' => 'ctools-auto-submit'),
    );

    $form['bottom row']['sort'] = array(
      '#type' => 'select',
      '#title' => t('Order'),
      '#options' => array(
        'asc' => t('Up'),
        'desc' => t('Down'),
      ),
      '#default_value' => 'asc',
      '#attributes' => array('class' => 'ctools-auto-submit'),
    );

    $form['bottom row']['submit'] = array(
      '#type' => 'submit',
      '#id' => 'ctools-export-ui-list-items-apply',
      '#value' => t('Apply'),
      '#attributes' => array('class' => 'ctools-use-ajax ctools-auto-submit-click'),
    );

    $form['bottom row']['reset'] = array(
      '#type' => 'submit',
      '#id' => 'ctools-export-ui-list-items-apply',
      '#value' => t('Reset'),
      '#attributes' => array('class' => 'ctools-use-ajax'),
    );

    ctools_add_js('ajax-responder');
    ctools_add_js('auto-submit');
    drupal_add_js('misc/jquery.form.js');
    ctools_add_js('export-ui-list.js');

    $form['#prefix'] = '<div class="clear-block">';
    $form['#suffix'] = '</div>';
  }

  /**
   * Validate the filter/sort form.
   *
   * It is very rare that a filter form needs validation, but if it is
   * needed, override this.
   */
  function list_form_validate(&$form, &$form_state) { }

  /**
   * Submit the filter/sort form.
   *
   * This submit handler is actually responsible for building up all of the
   * rows that will later be rendered, since it is doing the filtering and
   * sorting.
   *
   * For the most part, you should not need to override this method, as the
   * fiddly bits call through to other functions.
   */
  function list_form_submit(&$form, &$form_state) {
    // Filter and re-sort the pages.
    $plugin = $this->plugin;

    $prefix = ctools_export_ui_plugin_base_path($plugin);

    foreach ($this->items as $name => $item) {
      // Call through to the filter and see if we're going to render this
      // row. If it returns TRUE, then this row is filtered out.
      if ($this->list_filter($form_state, $item)) {
        continue;
      }

      // Note: Creating this list seems a little clumsy, but can't think of
      // better ways to do this.
      $allowed_operations = drupal_map_assoc(array_keys($plugin['allowed operations']));
      $not_allowed_operations = array('import');

      if ($item->type == t('Normal')) {
        $not_allowed_operations[] = 'revert';
      }
      elseif ($item->type == t('Overridden')) {
        $not_allowed_operations[] = 'delete';
      }
      else {
        $not_allowed_operations[] = 'revert';
        $not_allowed_operations[] = 'delete';
      }

      $not_allowed_operations[] = empty($item->disabled) ? 'enable' : 'disable';

      foreach ($not_allowed_operations as $op) {
        // Remove the operations that are not allowed for the specific
        // exportable.
        unset($allowed_operations[$op]);
      }

      $operations = array();

      foreach ($allowed_operations as $op) {
        $operations[$op] = array(
          'title' => $plugin['allowed operations'][$op]['title'],
          'href' => ctools_export_ui_plugin_menu_path($plugin, $op, $name),
        );
        if (!empty($plugin['allowed operations'][$op]['ajax'])) {
          $operations[$op]['attributes'] = array('class' => 'ctools-use-ajax');
        }
        if (!empty($plugin['allowed operations'][$op]['token'])) {
          $operations[$op]['query'] = array('token' => drupal_get_token($op));
        }
      }

      $this->list_build_row($item, $form_state, $operations);
    }

    // Now actually sort
    if ($form_state['values']['sort'] == 'desc') {
      arsort($this->sorts);
    }
    else {
      asort($this->sorts);
    }

    // Nuke the original.
    $rows = $this->rows;
    $this->rows = array();
    // And restore.
    foreach ($this->sorts as $name => $title) {
      $this->rows[$name] = $rows[$name];
    }
  }

  /**
   * Determine if a row should be filtered out.
   *
   * This handles the default filters for the export UI list form. If you
   * added additional filters in list_form() then this is where you should
   * handle them.
   *
   * @return
   *   TRUE if the item should be excluded.
   */
  function list_filter($form_state, $item) {
    if ($form_state['values']['storage'] != 'all' && $form_state['values']['storage'] != $item->type) {
      return TRUE;
    }

    if ($form_state['values']['disabled'] != 'all' && $form_state['values']['disabled'] != !empty($item->disabled)) {
      return TRUE;
    }

    if ($form_state['values']['search']) {
      $search = strtolower($form_state['values']['search']);
      foreach ($this->list_search_fields() as $field) {
        if (strpos(strtolower($item->$field), $search) !== FALSE) {
          $hit = TRUE;
          break;
        }
      }
      if (empty($hit)) {
        return TRUE;
      }
    }
  }

  /**
   * Provide a list of fields to test against for the default "search" widget.
   *
   * This widget will search against whatever fields are configured here. By
   * default it will attempt to search against the name, title and description fields.
   */
  function list_search_fields() {
    $fields = array(
      $this->plugin['export']['key'],
    );

    if (!empty($this->plugin['export']['admin_title'])) {
      $fields[] = $this->plugin['export']['admin_title'];
    }
    if (!empty($this->plugin['export']['admin_description'])) {
      $fields[] = $this->plugin['export']['admin_description'];
    }

    return $fields;
  }

  /**
   * Provide a list of sort options.
   *
   * Override this if you wish to provide more or change how these work.
   * The actual handling of the sorting will happen in build_row().
   */
  function list_sort_options() {
    if (!empty($this->plugin['export']['admin_title'])) {
      $options = array(
        'disabled' => t('Enabled, title'),
        $this->plugin['export']['admin_title'] => t('Title'),
      );
    }
    else {
      $options = array(
        'disabled' => t('Enabled, name'),
      );
    }

    $options += array(
      'name' => t('Name'),
      'storage' => t('Storage'),
    );

    return $options;
  }

  /**
   * Add listing CSS to the page.
   *
   * Override this if you need custom CSS for your list.
   */
  function list_css() {
    ctools_add_css('export-ui-list');
  }

  /**
   * Build a row based on the item.
   *
   * By default all of the rows are placed into a table by the render
   * method, so this is building up a row suitable for theme('table').
   * This doesn't have to be true if you override both.
   */
  function list_build_row($item, &$form_state, $operations) {
    // Set up sorting
    $name = $item->{$this->plugin['export']['key']};

    // Note: $item->type should have already been set up by export.inc so
    // we can use it safely.
    switch ($form_state['values']['order']) {
      case 'disabled':
        $this->sorts[$name] = empty($item->disabled) . $name;
        break;
      case 'title':
        $this->sorts[$name] = $item->{$this->plugin['export']['admin_title']};
        break;
      case 'name':
        $this->sorts[$name] = $name;
        break;
      case 'storage':
        $this->sorts[$name] = $item->type . $name;
        break;
    }

    $this->rows[$name]['data'] = array();
    $this->rows[$name]['class'] = !empty($item->disabled) ? 'ctools-export-ui-disabled' : 'ctools-export-ui-enabled';

    // If we have an admin title, make it the first row.
    if ($this->plugin['export']['admin_title']) {
      $this->rows[$name]['data'][] = array('data' => check_plain($item->{$this->plugin['export']['admin_title']}), 'class' => 'ctools-export-ui-title');
    }
    $this->rows[$name]['data'][] = array('data' => check_plain($name), 'class' => 'ctools-export-ui-name');
    $this->rows[$name]['data'][] = array('data' => check_plain($item->type), 'class' => 'ctools-export-ui-storage');
    $this->rows[$name]['data'][] = array('data' => theme('links', $operations), 'class' => 'ctools-export-ui-operations');

    // Add an automatic mouseover of the description if one exists.
    if (!empty($this->plugin['export']['admin_description'])) {
      $this->rows[$name]['title'] = $item->{$this->plugin['export']['admin_description']};
    }
  }

  /**
   * Provide the table header.
   *
   * If you've added columns via list_build_row() but are still using a
   * table, override this method to set up the table header.
   */
  function list_table_header() {
    $header = array();
    if ($this->plugin['export']['admin_title']) {
      $header[] = array('data' => t('Title'), 'class' => 'ctools-export-ui-title');
    }

    $header[] = array('data' => t('Name'), 'class' => 'ctools-export-ui-name');
    $header[] = array('data' => t('Storage'), 'class' => 'ctools-export-ui-storage');
    $header[] = array('data' => t('Operations'), 'class' => 'ctools-export-ui-operations');

    return $header;
  }

  /**
   * Render all of the rows together.
   *
   * By default we place all of the rows in a table, and this should be the
   * way most lists will go.
   *
   * Whatever you do if this method is overridden, the ID is important for AJAX
   * so be sure it exists.
   */
  function list_render(&$form_state) {
    return theme('table', $this->list_table_header(), $this->rows, array('id' => 'ctools-export-ui-list-items'));
  }

  /**
   * Render a header to go before the list.
   *
   * This will appear after the filter/sort widgets.
   */
  function list_header($form_state) { }

  /**
   * Render a footer to go after thie list.
   *
   * This is a good place to add additional links.
   */
  function list_footer($form_state) { }

  // ------------------------------------------------------------------------
  // These methods are the API for adding/editing exportable items

  function add_page($js, $input) {
    drupal_set_title($this->plugin['strings']['title']['add']);

    $form_state = array(
      'plugin' => $this->plugin,
      'object' => &$this,
      'ajax' => $js,
      'item' => ctools_export_crud_new($this->plugin['schema']),
      'op' => 'add',
      'rerender' => TRUE,
      'no_redirect' => TRUE,
    );

    $output = $this->edit_execute_form($form_state);
    if (!empty($form_state['executed'])) {
      $export_key = $this->plugin['export']['key'];
      drupal_goto(str_replace('%ctools_export_ui', $form_state['item']->{$export_key}, $this->plugin['redirect']['add']));
    }

    return $output;
  }

  /**
   * Main entry point to edit an item.
   *
   * The default implementation simply uses a form, so this should be
   * overridden for more complex implentations that need more than to display
   * a simple form (like a view or a page manager page).
   */
  function edit_page($js, $input, $item) {
    // Replace %title that might be there with the exportable title.
    $export_key = $this->plugin['export']['key'];
    drupal_set_title(str_replace('%title', check_plain($item->{$export_key}), $this->plugin['strings']['title']['edit']));
    $form_state = array(
      'plugin' => $this->plugin,
      'object' => &$this,
      'ajax' => $js,
      'item' => $item,
      'op' => 'edit',
      'rerender' => TRUE,
      'no_redirect' => TRUE,
    );

    $output = $this->edit_execute_form($form_state);
    if (!empty($form_state['executed'])) {
      $export_key = $this->plugin['export']['key'];
      drupal_goto(str_replace('%ctools_export_ui', $form_state['item']->{$export_key}, $this->plugin['redirect']['edit']));
    }

    return $output;
  }

  function clone_page($js, $input, $item) {
    $export_key = $this->plugin['export']['key'];
    drupal_set_title(str_replace('%title', check_plain($item->{$export_key}), $this->plugin['strings']['title']['clone']));

    // Tabs and breadcrumb disappearing, this helps alleviate through cheating.
    ctools_include('menu');
    $trail = ctools_get_menu_trail(ctools_export_ui_plugin_base_path($this->plugin));
    menu_set_active_trail($trail);

    // To make a clone of an item, we first export it and then re-import it.
    // Export the handler, which is a fantastic way to clean database IDs out of it.
    $export = ctools_export_crud_export($this->plugin['schema'], $item);
    $item = ctools_export_crud_import($this->plugin['schema'], $export);
    $item->{$this->plugin['export']['key']} = 'clone_of_' . $item->name;

    $form_state = array(
      'plugin' => $this->plugin,
      'object' => &$this,
      'ajax' => $js,
      'item' => $item,
      'op' => 'add',
      'rerender' => TRUE,
      'no_redirect' => TRUE,
    );

    $output = $this->edit_execute_form($form_state);
    if (!empty($form_state['executed'])) {
      $export_key = $this->plugin['export']['key'];
      drupal_goto(str_replace('%ctools_export_ui', $form_state['item']->{$export_key}, $this->plugin['redirect']['clone']));
    }

    return $output;
  }

  /**
   * Execute the form.
   *
   * Add and Edit both funnel into this, but they have a few different
   * settings.
   */
  function edit_execute_form(&$form_state) {
    ctools_include('form');
    $output = ctools_build_form('ctools_export_ui_edit_item_form', $form_state);
    if (!empty($form_state['executed'])) {
      $this->edit_save_form($form_state);
    }

    return $output;
  }

  /**
   * Called to save the final product from the edit form.
   */
  function edit_save_form($form_state) {
    $item = &$form_state['item'];
    $export_key = $this->plugin['export']['key'];

    $result = ctools_export_crud_save($this->plugin['schema'], $item);

    if ($result) {
      $message = str_replace('%title', check_plain($item->{$export_key}), $this->plugin['strings']['confirmation'][$form_state['op']]['success']);
      drupal_set_message($message);
    }
    else {
      $message = str_replace('%title', check_plain($item->{$export_key}), $this->plugin['strings']['confirmation'][$form_state['op']]['fail']);
      drupal_set_message($message, 'error');
    }
  }

  /**
   * Provide the actual editing form.
   */
  function edit_form(&$form, &$form_state) {
    $export_key = $this->plugin['export']['key'];
    $item = $form_state['item'];
    $schema = ctools_export_get_schema($this->plugin['schema']);

    // TODO: Drupal 7 has a nifty method of auto guessing names from
    // titles that is standard. We should integrate that here as a
    // nice standard.
    // Guess at a couple of our standard fields.
    if (!empty($this->plugin['export']['admin_title'])) {
      $form['info'][$this->plugin['export']['admin_title']] = array(
        '#type' => 'textfield',
        '#title' => t('Administrative title'),
        '#description' => t('This will appear in the administrative interface to easily identify it.'),
        '#default_value' => $item->{$this->plugin['export']['admin_title']},
      );
    }

    $form['info'][$export_key] = array(
      '#title' => t($schema['export']['key name']),
      '#type' => 'textfield',
      '#default_value' => $item->{$export_key},
      '#description' => t('The unique ID for this @export', array('@export' => $this->plugin['title'])),
      '#required' => TRUE,
      '#maxlength' => 255,
    );

    if ($form_state['op'] === 'edit') {
      $form['info'][$export_key]['#disabled'] = TRUE;
      $form['info'][$export_key]['#value'] = $item->{$export_key};
    }
    else {
      $form['info'][$export_key]['#element_validate'] = array('ctools_export_ui_edit_name_validate');
    }

    if (!empty($this->plugin['export']['admin_description'])) {
      $form['info'][$this->plugin['export']['admin_description']] = array(
        '#type' => 'textarea',
        '#title' => t('Administrative description'),
        '#default_value' => $item->{$this->plugin['export']['admin_description']},
      );
    }

    // Add plugin's form definitions.
    if (!empty($this->plugin['form']['settings'])) {
      // Pass $form by reference.
      $this->plugin['form']['settings']($form, $form_state);
    }

    // Add the buttons if the wizard is not in use.
    if (empty($form_state['form_info'])) {
      // Make sure that whatever happens, the buttons go to the bottom.
      $form['buttons']['#weight'] = 100;

      // Add buttons.
      $form['buttons']['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Save'),
      );

      $form['buttons']['delete'] = array(
        '#type' => 'submit',
        '#value' => $item->export_type & EXPORT_IN_CODE ? t('Revert') : t('Delete'),
        '#access' => $form_state['op'] === 'edit' && $item->export_type & EXPORT_IN_DATABASE,
        '#submit' => 'ctools_export_ui_edit_name_validate',
      );
    }
  }

  /**
   * Validate callback for the edit form.
   */
  function edit_form_validate(&$form, &$form_state) {
    if (!empty($this->plugin['form']['validate'])) {
      // Pass $form by reference.
      $this->plugin['form']['validate']($form, $form_state);
    }
  }

  /**
   * Handle the submission of the edit form.
   *
   * At this point, submission is successful. Our only responsibility is
   * to copy anything out of values onto the item that we are able to edit.
   *
   * If the keys all match up to the schema, this method will not need to be
   * overridden.
   */
  function edit_form_submit(&$form, &$form_state) {
    if (!empty($this->plugin['form']['submit'])) {
      // Pass $form by reference.
      $this->plugin['form']['submit']($form, $form_state);
    }

    // Transfer data from the form to the $item based upon schema values.
    $schema = ctools_export_get_schema($this->plugin['schema']);
    foreach (array_keys($schema['fields']) as $key) {
      if(isset($form_state['values'][$key])) {
        $form_state['item']->{$key} = $form_state['values'][$key];
      }
    }
  }

  // ------------------------------------------------------------------------
  // These methods are the API for 'other' stuff with exportables such as
  // enable, disable, import, export, delete

  /**
   * Callback to enable a page.
   */
  function enable_page($js, $input, $item) {
    return $this->set_item_state(FALSE, $js, $input, $item);
  }

  /**
   * Callback to disable a page.
   */
  function disable_page($js, $input, $item) {
    return $this->set_item_state(TRUE, $js, $input, $item);
  }

  /**
   * Set an item's state to enabled or disabled and output to user.
   *
   * If javascript is in use, this will rebuild the list and send that back
   * as though the filter form had been executed.
   */
  function set_item_state($state, $js, $input, $item) {
    ctools_export_set_object_status($item, $state);

    if (!$js) {
      drupal_goto(ctools_export_ui_plugin_base_path($this->plugin));
    }
    else {
      return $this->list_page($js, $input);
    }
  }

  /**
   * Page callback to delete an exportable item.
   */
  function delete_page($js, $input, $item) {
    $form_state = array(
      'plugin' => $this->plugin,
      'object' => &$this,
      'ajax' => $js,
      'item' => $item,
      'op' => $item->export_type & EXPORT_IN_CODE ? 'revert' : 'delete',
      'rerender' => TRUE,
      'no_redirect' => TRUE,
    );

    ctools_include('form');

    $output = ctools_build_form('ctools_export_ui_delete_confirm_form', $form_state);
    if (!empty($form_state['executed'])) {
      ctools_export_crud_delete($this->plugin['schema'], $item);
      $export_key = $this->plugin['export']['key'];
      $message = str_replace('%title', check_plain($item->{$export_key}), $this->plugin['strings']['confirmation'][$form_state['op']]['success']);
      drupal_set_message($message);
      drupal_goto(ctools_export_ui_plugin_base_path($this->plugin));
    }

    return $output;
  }

  /**
   * Page callback to display export information for an exportable item.
   */
  function export_page($js, $input, $item) {
    drupal_set_title(str_replace('%title', check_plain($this->plugin['title']), $this->plugin['strings']['title']['export']));
    return drupal_get_form('ctools_export_form', ctools_export_crud_export($this->plugin['schema'], $item), t('Export'));
  }

  /**
   * Page callback to import information for an exportable item.
   */
  function import_page($js, $input, $step = 'begin') {
    // Import is basically a multi step wizard form, so let's go ahead and
    // use CTools' wizard.inc for it.
    drupal_set_title(str_replace('%title', check_plain($this->plugin['title']), $this->plugin['strings']['title']['import']));

    $form_info = array(
      'id' => 'ctools_export_ui_import',
      'path' => ctools_export_ui_plugin_base_path($this->plugin) . '/' . $this->plugin['menu']['items']['import']['path'] . '/%step',
      'return path' => $this->plugin['redirect']['import'],
      'show trail' => TRUE,
      'show back' => TRUE,
      'show return' => FALSE,
      'finish callback' => 'ctools_export_ui_import_finish',
      'cancel callback' => 'ctools_export_ui_import_cancel',
      'order' => array(
        'code' => t('Import code'),
        'edit' => t('Edit'),
      ),
      'forms' => array(
        'code' => array(
          'form id' => 'ctools_export_ui_import_code'
        ),
        'edit' => array(
          'form id' => 'ctools_export_ui_import_edit'
        ),
      ),
    );

    $form_state = array(
      'plugin' => $this->plugin,
      'input' => $input,
      'rerender' => TRUE,
      'no_redirect' => TRUE,
      'object' => &$this,
      'export' => '',
      'overwrite' => FALSE,
    );

    if ($step == 'code') {
      // This is only used if the BACK button was hit.
      if (!empty($_SESSION['ctools_export_ui_import'][$this->plugin['name']])) {
        $form_state['item'] = $_SESSION['ctools_export_ui_import'][$this->plugin['name']];
        $form_state['export'] = $form_state['item']->export_ui_code;
        $form_state['overwrite'] = $form_state['item']->export_ui_allow_overwrite;
      }
    }
    else if ($step == 'begin') {
      $step = 'code';
      if (!empty($_SESSION['ctools_export_ui_import'][$this->plugin['name']])) {
        unset($_SESSION['ctools_export_ui_import'][$this->plugin['name']]);
      }
    }
    else if ($step != 'code') {
      $form_state['item'] = $_SESSION['ctools_export_ui_import'][$this->plugin['name']];
      $form_state['op'] = 'add';
      if (!empty($form_state['item']->export_ui_allow_overwrite)) {
        // if allow overwrite was enabled, set this to 'edit' only if the key already existed.
        $export_key = $this->plugin['export']['key'];

        if (ctools_export_crud_load($this->plugin['schema'], $form_state['item']->{$export_key})) {
          $form_state['op'] = 'edit';
        }
      }
    }

    ctools_include('wizard');
    return ctools_wizard_multistep_form($form_info, $step, $form_state);
  }
}

// -----------------------------------------------------------------------
// Forms to be used with this class.
//
// Since Drupal's forms are completely procedural, these forms will
// mostly just be pass-throughs back to the object.

/**
 * Form callback to handle the filter/sort form when listing items.
 *
 * This simply loads the object defined in the plugin and hands it off.
 */
function ctools_export_ui_list_form(&$form_state) {
  $form = array();
  $form_state['object']->list_form($form, $form_state);
  return $form;
}

/**
 * Validate handler for ctools_export_ui_list_form.
 */
function ctools_export_ui_list_form_validate(&$form, &$form_state) {
  $form_state['object']->list_form_validate($form, $form_state);
}

/**
 * Submit handler for ctools_export_ui_list_form.
 */
function ctools_export_ui_list_form_submit(&$form, &$form_state) {
  $form_state['object']->list_form_submit($form, $form_state);
}

/**
 * Form callback to edit an exportable item.
 *
 * This simply loads the object defined in the plugin and hands it off.
 */
function ctools_export_ui_edit_item_form(&$form_state) {
  $form = array();
  $form_state['object']->edit_form($form, $form_state);
  return $form;
}

/**
 * Validate handler for ctools_export_ui_edit_item_form.
 */
function ctools_export_ui_edit_item_form_validate(&$form, &$form_state) {
  $form_state['object']->edit_form_validate($form, $form_state);
}

/**
 * Submit handler for ctools_export_ui_edit_item_form.
 */
function ctools_export_ui_edit_item_form_submit(&$form, &$form_state) {
  $form_state['object']->edit_form_submit($form, $form_state);
}

/**
 * Submit handler to delete for ctools_export_ui_edit_item_form
 */
function ctools_export_ui_edit_item_form_delete(&$form, &$form_state) {
  $export_key = $form_state['plugin']['export']['key'];

  $form_state['redirect'] = ctools_export_ui_plugin_menu_path($form_state['plugin'], 'delete', $form_state['item']->{$export_key});
}

/**
 * Validate that an export item name is acceptable and unique during add.
 */
function ctools_export_ui_edit_name_validate($element, &$form_state) {
  $plugin = $form_state['plugin'];
  // Check for string identifier sanity
  if (!preg_match('!^[a-z0-9_]+$!', $element['#value'])) {
    form_error($element, t('The export id can only consist of lowercase letters, underscores, and numbers.'));
    return;
  }

  // Check for name collision
  if ($exists = ctools_export_crud_load($plugin['schema'], $element['#value'])) {
    form_error($element, t('A @plugin with this name already exists. Please choose another name or delete the existing item before creating a new one.', array('@plugin' => $plugin['title singular'])));
  }
}

/**
 * Delete/Revert confirm form.
 */
function ctools_export_ui_delete_confirm_form(&$form_state) {
  $plugin = $form_state['plugin'];
  $item = $form_state['item'];

  $form = array();

  $export_key = $plugin['export']['key'];
  $question = str_replace('%title', check_plain($item->{$export_key}), $plugin['strings']['confirmation'][$form_state['op']]['question']);

  $form = confirm_form($form,
    $question,
    ctools_export_ui_plugin_base_path($plugin),
    $plugin['strings']['confirmation'][$form_state['op']]['information'],
    drupal_ucfirst($plugin['allowed operations'][$form_state['op']]['title']), t('Cancel')
  );
  return $form;
}

/**
 * Import form. Provides simple helptext instructions and textarea for
 * pasting a export definition.
 *
 * This is a wizard form so its input is slightly different.
 */
function ctools_export_ui_import_code(&$form, &$form_state) {
  $plugin = $form_state['plugin'];

  $form['help'] = array(
    '#type' => 'item',
    '#value' => $plugin['strings']['help']['import'],
  );

  $form['import'] = array(
    '#title' => t('@plugin object', array('@plugin' => $plugin['title singular proper'])),
    '#type' => 'textarea',
    '#rows' => 10,
    '#required' => TRUE,
    '#default_value' => $form_state['export'],
  );

  $form['overwrite'] = array(
    '#title' => t('Allow import to overwrite an existing record.'),
    '#type' => 'checkbox',
    '#default_value' => $form_state['overwrite'],
  );
}

/**
 * Import edit form
 *
 * This is a wizard form so its input is slightly different. But it just
 * passes through to the normal edit form.
 */
function ctools_export_ui_import_edit(&$form, &$form_state) {
  $form_state['object']->edit_form($form, $form_state);
}

/**
 * Validate handler for ctools_export_ui_import_edit.
 */
function ctools_export_ui_import_edit_validate(&$form, &$form_state) {
  $form_state['object']->edit_form_validate($form, $form_state);
}

/**
 * Submit handler for ctools_export_ui_import_edit.
 */
function ctools_export_ui_import_edit_submit(&$form, &$form_state) {
  $form_state['object']->edit_form_submit($form, $form_state);
}

/**
 * Import form validate handler.
 *
 * Evaluates code and make sure it creates an object before we continue.
 */
function ctools_export_ui_import_code_validate($form, &$form_state) {
  $plugin = $form_state['plugin'];
  $item = ctools_export_crud_import($plugin['schema'], $form_state['values']['import']);
  if (is_string($item)) {
    form_error($form['import'], t('Unable to get an import from the code. Errors reported: @errors', array('@errors' => $item)));
    return;
  }

  $form_state['item'] = $item;
  $form_state['item']->export_ui_allow_overwrite = $form_state['values']['overwrite'];
  $form_state['item']->export_ui_code = $form_state['values']['import'];
}

/**
 * Submit callback for import form.
 *
 * Stores the item in the session.
 */
function ctools_export_ui_import_code_submit($form, &$form_state) {
  $_SESSION['ctools_export_ui_import'][$form_state['plugin']['name']] = $form_state['item'];
}

/**
 * Wizard finish callback for import of exportable item.
 */
function ctools_export_ui_import_finish(&$form_state) {
  // This indicates that overwrite was allowed, so we should delete the
  // original item.
  if ($form_state['op'] == 'edit') {
    ctools_export_crud_delete($this->plugin['schema'], $form_state['item']);
  }

  $form_state['object']->edit_save_form($form_state);

  // Clear temporary data from session.
  unset($_SESSION['ctools_export_ui_import'][$form_state['plugin']['name']]);
}

/**
 * Wizard cancel callback for import of exportable item.
 */
function ctools_export_ui_import_cancel(&$form_state) {
  // Clear temporary data from session.
  unset($_SESSION['ctools_export_ui_import'][$form_state['plugin']['name']]);
}