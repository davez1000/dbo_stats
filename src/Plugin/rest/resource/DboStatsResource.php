<?php

namespace Drupal\dbo_stats\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;

/**
 * Provides a DBO_STATS Resource.
 *
 * @RestResource(
 *   id = "dbo_stats_resource",
 *   label = @Translation("DBO Stats"),
 *   uri_paths = {
 *     "canonical" = "/dbo_stats/{type}/{date}/{role}/{limit}/{sort}"
 *   }
 * )
 */
class DboStatsResource extends ResourceBase {

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
   * Responds to entity GET requests.
   *
   * @param $type
   * @param $date
   * @param $role
   * @param $limit
   * @param $sort
   *
   * @return \Drupal\rest\ResourceResponse
   */
  public function get($type, $date, $role, $limit, $sort) {

    $build = [
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if ($type == 'active') {
      return (new ResourceResponse($this->_getActiveUsers()))
        ->addCacheableDependency($build);
    }

    if ($type == 'online') {
      return (new ResourceResponse($this->_getOnlineUsers()))
        ->addCacheableDependency($build);
    }

    if ($type == 'fn_readership') {
      return new ResourceResponse([
        'data' => $this->_fnReadership(),
      ]);
    }

    if ($type == 'searchterms') {
      return (new ResourceResponse($this->_searchTerms()))
        ->addCacheableDependency($build);
    }

    if ($type == 'roles') {
      return (new ResourceResponse([
        'data' => $this->_getUserRoles(),
      ]))
        ->addCacheableDependency($build);
    }

    if (!preg_match('/^popular|hitsbyrole|failedsearches$/', $type)) {
      return (new ResourceResponse([
        'message' => 'incorrect type',
        'error'   => TRUE,
      ]))
        ->addCacheableDependency($build);
    }
    // If the date is not set, use today's date for the filter.
    if (!$date) {
      $time = \Drupal::time()->getRequestTime();
      $date = \Drupal::service('date.formatter')
                     ->format($time, 'custom', 'ymd');
    }

    try {
      $db = \Drupal::database();

      switch ($type) {
        case 'popular':
        case 'hitsbyrole':
          $table = 'dbo_stats_counter';
          break;
        case 'failedsearches':
          $table = 'dbo_stats_failed_searches';
          break;
      }

      $query = $db->select($table, 'a');
      $query->fields('a');

      // Check to see if we have a range of dates.
      if (preg_match('/^(\d+)\,(\d+)$/', $date, $m)) {
        $query->condition('dmy', [$m[1], $m[2]], 'BETWEEN');
      }
      else {
        $query->condition('dmy', $date, '=');
      }

      if ($role) {
        if (preg_match('/,/', $role)) {
          $role_parts = explode(',', $role);
          $query->condition('role', $role_parts, 'IN');
        }
        else {
          $query->condition('role', $role, '=');
        }
      }

      switch ($type) {
        case 'popular':
          $query->join('node_field_data', 'nfd', 'a.nid = nfd.nid');
          $query->fields('nfd', [
            'title',
            'type',
            'status',
            'created',
            'changed',
          ]);
          $query->orderBy('count', 'DESC');
          break;
        case 'hitsbyrole':
          $query->orderBy('count', 'DESC');
          break;
        case 'failedsearches':
          $query->addExpression('success + fail', 'totalsearches');
          break;
      }

      $limit = $limit > 0 ? $limit : 25;
      $query->range(0, $limit);

      $results = $query->execute()->fetchAll();

      if (!empty($results)) {
        $lines = [];
        foreach ($results as $result) {
          if (in_array($result->role, $this->_exclude_roles)) {
            continue;
          }
          switch ($type) {
            case 'popular':
              $aurl = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $result->nid);
              @$lines[$result->nid]['count'] += $result->count;
              @$lines[$result->nid]['nid'] = (int) $result->nid;
              @$lines[$result->nid]['title'] = $result->title;
              @$lines[$result->nid]['aurl'] = $aurl;
              @$lines[$result->nid]['type'] = $result->type;
              @$lines[$result->nid]['status'] = $result->status;
              @$lines[$result->nid]['created'] = $result->created;
              @$lines[$result->nid]['changed'] = $result->changed;
              @$lines[$result->nid]['dmy'] = $result->dmy;
              break;
            case 'hitsbyrole':
              @$lines[$result->role]['count'] += $result->count;
              @$lines[$result->role]['role_machine_name'] = $result->role;
              foreach ($this->_getUserRoles() as $k => $r) {
                if ($r['machine_name'] == $result->role) {
                  @$lines[$result->role]['role_name'] = $r['name'];
                }
              }
              // @$lines[$result->role]['role_name'] = $this->_getUserRoles()[$result->role];
              break;
            case 'failedsearches':
              foreach ($this->_getUserRoles() as $k => $r) {
                if ($r['machine_name'] == $result->role) {
                  @$lines[$result->role]['role'] = $r['name'];
                }
              }
              // @$lines[$result->role]['role'] = $this->_getUserRoles()[$result->role]['name'];
              @$lines[$result->role]['success'] = $result->success;
              @$lines[$result->role]['fail'] = $result->fail;
              @$lines[$result->role]['totalsearches'] = (int) $result->totalsearches;
              break;
          }
        }

        ($sort == 'asc') ? asort($lines) : arsort($lines);

        $data = [];
        foreach ($lines as $k => $v) {
          switch ($type) {
            case 'popular':
              $data[] = [
                'node' => $v,
              ];
              break;
            case 'hitsbyrole':
              $data[] = [
                'role' => $v,
              ];
              break;
            case 'failedsearches':
              $data[] = $v;
              break;
          }
        }
      }
      else {
        $data = [
          'message' => 'no data',
          'error'   => TRUE,
        ];
      }
    } catch (\Exception $e) {
      $this->logger->warning($e->getMessage());
      $data = [
        'error' => TRUE,
      ];
    }

    return (new ResourceResponse($data))
      ->addCacheableDependency($build);

  }

  /**
   * Returns counts of active users within:
   * 1 hour
   * 3 hours
   * 6 hours
   * 24 hours
   *
   * @return array
   */
  protected function _getActiveUsers() {
    try {
      $user_data = [];
      $now       = \Drupal::time()->getRequestTime();
      $db        = \Drupal::database();
      $times     = [1, 3, 6, 24];
      foreach ($times as $time) {
        $user_data[$time] = $db->select('users_field_data', 'a')
                               ->fields('a', ['uid', 'access'])
                               ->condition('access', [
                                 $now - ($time * 60 * 60),
                                 $now,
                               ], 'BETWEEN')
                               ->condition('name', [
                                 'mi_dashboard',
                                 'admin',
                               ], 'NOT IN')
                               ->countQuery()
                               ->execute()
                               ->fetchField();
      }
      return [$user_data];
    } catch (\Exception $e) {
      $this->logger->warning($e->getMessage());
      return [];
    }
  }

  /**
   * Returns a list of users that are online.
   *
   * @return array
   *  Associate array.
   */
  protected function _getOnlineUsers() {
    try {
      $return_user_data = [];
      $db               = \Drupal::database();
      $access_time      = $db->select('dbo_stats_access_time', 'a')
                             ->fields('a')
                             ->execute()
                             ->fetchAll();

      if (!empty($access_time)) {
        $now   = \Drupal::time()->getRequestTime();
        $users = [];
        foreach ($access_time as $item) {
          if ($item->time >= $now - (60 * 60) && $item->time <= $now) {
            $users[$item->uid] = $item->uid;
          }
        }
        // Unset admin.
        unset($users[1]);
        $user_data = \Drupal::service('entity_type.manager')
                            ->getStorage('user')
                            ->loadMultiple($users);
        foreach ($user_data as $ud) {
          $return_user_data[] = [
            'name' => $ud->name->value,
            'mail' => $ud->mail->value,
          ];
        }
        return $return_user_data;
      }
    } catch (\Exception $e) {
      $this->logger->warning($e->getMessage());
      return [];
    }
  }

  /**
   * Returns a list of users that have read field_notices.
   *
   * @return array
   *  Associative array.
   */
  protected function _fnReadership() {
    $fnv_data             = [];
    $field_notice_viewers = \Drupal::state()
                                   ->get('dbo_stats_field_notice_viewers') ?? [];
    if (!empty($field_notice_viewers)) {
      foreach ($field_notice_viewers as $key => $val) {
        foreach ($val as $viewer) {
          $fnv_data[$viewer][] = $key;
        }
      }

    }
    return $fnv_data;
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

  /**
   * Return failed search terms.
   *
   * @return array
   *  Associative array.
   */
  protected function _searchTerms() {
    try {
      $data  = [];
      $db    = \Drupal::database();
      $terms = $db->select('dbo_stats_search_terms', 'a')
                  ->fields('a')
                  ->condition('fail', 1, '=')
                  ->execute()
                  ->fetchAll();
      if (!empty($terms)) {
        foreach ($terms as $term) {
          $data[] = $term->term;
        }
        return $data;
      }
    } catch (\Exception $e) {
      $this->logger->warning($e->getMessage());
      return [];
    }
  }

}
