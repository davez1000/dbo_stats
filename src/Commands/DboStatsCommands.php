<?php

namespace Drupal\kb_stats\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\Exception\FileException;

/**
 * Drush commands.
 */
class KbStatsCommands extends DrushCommands {

  /**
   * A list of roles that will be excluded.
   *
   * @var array
   */
  protected $_exclude_roles = [
    'administrator',
    'authenticated',
    'system_administrator',
    'cms_administrator',
  ];

  /**
   * MI Dashboard export data.
   * This exports search terms.
   * Output to CSV, for importing into Excel, or similar.
   *
   * @command kb:stats-search-terms
   * @aliases kb-stats-search-terms
   */
  public function statsSearchTerms() {
    $file_system = \Drupal::service('file_system');
    $db = \Drupal::database();

    $query = $db->select('kb_stats_search_terms', 'a');
    $query->fields('a');
    $results = $query->execute()->fetchAll();

    $results_line = "Search term|||||Number of successful searches|||||Number of unsuccessful searches\n";
    if (!empty($results)) {
      foreach ($results as $item) {
        $term = preg_replace('/"/', '', trim($item->term));
        $results_line .=
          $term .
          '|||||' .
          $item->success .
          '|||||' .
          $item->fail .
          "\n";
      }
      $file_system->saveData($results_line, 'public://mi_exports/search_terms/search_terms.csv', FileSystemInterface::EXISTS_REPLACE);
      $this->output()->writeln('DONE');
    }
  }

  /**
   * MI Dashboard export data.
   * This exports failed searches data.
   * Output to CSV, for importing into Excel, or similar.
   *
   * @command kb:stats-failed-searches
   * @aliases kb-stats-failed-searches
   */
  public function statsFailedSearches() {
    $file_system = \Drupal::service('file_system');
    $db = \Drupal::database();

    $roles = $this->_getUserRoles();
    foreach ($roles as $rl) {
      if (in_array($rl['machine_name'], $this->_exclude_roles)) {
        continue;
      }

      $role = $rl['machine_name'];

      $query = $db->select('kb_stats_failed_searches', 'a');
      $query->fields('a');

      if (!empty($role)) {
        if (preg_match('/,/', $role)) {
          $role_parts = explode(',', $role);
          // We are not using multiple roles in this export.
          $query->condition('role', $role_parts, 'IN');
        } else {
          $query->condition('role', $role, '=');
        }
      }

      $results = $query->execute()->fetchAll();

      // Output.
      $results_line = "Date: YYYYMMDD|||||Total searches|||||Successful searches (results >= 1)|||||Failed searches (0 results)\n";
      if (!empty($results)) {
        foreach ($results as $item) {
          $results_line .=
            '20' . $item->dmy .
            '|||||' .
            ($item->success + $item->fail) .
            '|||||' .
            $item->success .
            '|||||' .
            $item->fail .
            "\n";
        }
        $file_system->saveData($results_line, 'public://mi_exports/failed_searches/' . $role . '.csv', FileSystemInterface::EXISTS_REPLACE);
        $this->output()->writeln($role . ' DONE');
      }
    }
  }

  /**
   * MI Dashboard export data.
   * This exports popular content search by role and date range,
   * or total page hits by role and date (they are both the same).
   * Output to CSV, for importing into Excel, or similar.
   *
   * @command kb:stats-popular-content-by-role
   * @aliases kb-stats-popular-content-by-role
   */
  public function statsPopularContentByRole() {
    $file_system = \Drupal::service('file_system');
    $db = \Drupal::database();

    $roles = $this->_getUserRoles();
    foreach ($roles as $rl) {
      if (in_array($rl['machine_name'], $this->_exclude_roles)) {
        continue;
      }

      $role = $rl['machine_name'];

      $query = $db->select('kb_stats_counter', 'a');
      $query->fields('a');

      if (!empty($role)) {
        if (preg_match('/,/', $role)) {
          $role_parts = explode(',', $role);
          // We are not using multiple roles in this export.
          $query->condition('role', $role_parts, 'IN');
        } else {
          $query->condition('role', $role, '=');
        }
      }

      $query->join('node_field_data', 'nfd', 'a.nid = nfd.nid');
      $query->fields('nfd', [
        'title',
        'type',
        'status',
        'created',
        'changed',
      ]);
      $query->orderBy('count', 'DESC');

      $results = $query->execute()->fetchAll();

      // Output.
      $results_line = "Date: YYYYMMDD|||||Hit Count|||||Content ID|||||Title|||||URL|||||Content Type|||||Published\n";
      if (!empty($results)) {
        foreach ($results as $item) {
          $url_prefix = 'https://knowledge-internal.censuskb.aws.abs.gov.au';
          $aurl = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $item->nid);
          $title = preg_replace('/\s{2,}/', ' ', trim($item->title));
          $published = ($item->status) ? 'Yes' : 'No';
          $type = ($item->type == 'filed_notices') ? 'field_notices' : $item->type;
          $results_line .=
            '20' . $item->dmy .
            '|||||' .
            $item->count .
            '|||||' .
            $item->nid .
            '|||||' .
            $title .
            '|||||' .
            $url_prefix . $aurl .
            '|||||' .
            $type .
            '|||||' .
            $published .
            "\n";
        }
        $file_system->saveData($results_line, 'public://mi_exports/page_hits/' . $role . '.csv', FileSystemInterface::EXISTS_REPLACE);
        $this->output()->writeln($role . ' DONE');
      }
    }
  }

  /**
   * MI Dashboard export data.
   * This exports hits for all content, regardless of role or date.
   * Output to CSV, for importing into Excel, or similar.
   *
   * @command kb:stats-total-hits-by-content
   * @aliases kb-stats-total-hits-by-content
   */
  public function statsTotalHitsByContent() {
    $file_system = \Drupal::service('file_system');
    $db = \Drupal::database();

    $query = $db->select('kb_stats_counter', 'a');
    $query->fields('a', [
      'nid',
      'count',
    ]);

    $query->join('node_field_data', 'nfd', 'a.nid = nfd.nid');
    $query->fields('nfd', [
      'title',
      'type',
      'status',
      'created',
      'changed',
    ]);
    $query->orderBy('count', 'DESC');
    $results = $query->execute()->fetchAll();

    $added_items = [];
    if (!empty($results)) {
      foreach ($results as $item) {
        $added_items[$item->nid]['totalcount'] += $item->count;
        $added_items[$item->nid]['nid'] = $item->nid;
        $added_items[$item->nid]['title'] = $item->title;
        $added_items[$item->nid]['type'] = $item->type;
        $added_items[$item->nid]['status'] = $item->status;
      }
    }

    // Output.
    $results_line = "Hit Count|||||Content ID|||||Title|||||URL|||||Content Type|||||Published\n";
    if (!empty($added_items)) {
      foreach ($added_items as $item) {
        $url_prefix = 'https://knowledge-internal.censuskb.aws.abs.gov.au';
        $aurl = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $item['nid']);
        $title = preg_replace('/\s{2,}/', ' ', trim($item['title']));
        $title = preg_replace('/,\s{1,}/', ' ', $title);
        $published = ($item['status']) ? 'Yes' : 'No';
        $type = ($item['type'] == 'filed_notices') ? 'field_notices' : $item['type'];
        $results_line .=
          $item['totalcount'] .
          '|||||' .
          $item['nid'] .
          '|||||' .
          $title .
          '|||||' .
          $url_prefix . $aurl .
          '|||||' .
          $type .
          '|||||' .
          $published .
          "\n";
      }
      $file_system->saveData($results_line, 'public://mi_exports/total_hits/total_hits_by_content.csv', FileSystemInterface::EXISTS_REPLACE);
      $this->output()->writeln('DONE');
    }
  }

  /**
   * Returns user roles and role names.
   *
   * @return array
   *  Associative array of role machine names and role names.
   */
  protected function _getUserRoles() {
    $roles = user_role_names(TRUE);
    $r     = [];
    foreach ($roles as $key => $val) {
      if (in_array($key, $this->_exclude_roles)) {
        continue;
      }
      $r[] = [
        'machine_name' => $key,
        'name'         => $val,
      ];
    }
    return $r;
  }
  
}
