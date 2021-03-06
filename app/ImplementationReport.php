<?php
namespace App;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;
use ORM;
use GuzzleHttp;
use Config;
use GuzzleHttp\Exception\RequestException;

class ImplementationReport {

  private $user;
  private $endpoint;
  private $client;

  public function get_server_report(ServerRequestInterface $request, ResponseInterface $response, $args) {
    if($check = $this->_server_report($request, $response, $args))
      return $check;

    $results = ORM::for_table('tests')
      ->raw_query('SELECT features.*, feature_results.implements FROM features
        LEFT JOIN feature_results ON features.number = feature_results.feature_num
          AND feature_results.endpoint_id = :endpoint_id
        WHERE features.group = "server"
        ORDER BY features.number', ['endpoint_id'=>$this->endpoint->id])
      ->find_many();

    $response->getBody()->write(view('implementation-report', [
      'title' => 'Micropub Rocks!',
      'endpoint' => $this->endpoint,
      'results' => $results,
    ]));
    return $response;
  }

  public function get_client_report(ServerRequestInterface $request, ResponseInterface $response, $args) {
    if($check = $this->_client_report($request, $response, $args))
      return $check;

    $results = ORM::for_table('tests')
      ->raw_query('SELECT features.*, feature_results.implements FROM features
        LEFT JOIN feature_results ON features.number = feature_results.feature_num
          AND feature_results.client_id = :client_id
        WHERE features.group = "client"
        ORDER BY features.number', ['client_id'=>$this->client->id])
      ->find_many();

    $response->getBody()->write(view('implementation-report-client', [
      'title' => 'Micropub Rocks!',
      'client' => $this->client,
      'results' => $results,
    ]));
    return $response;
  }

  public function view_server_report(ServerRequestInterface $request, ResponseInterface $response, $args) {
    if($check = $this->_server_report($request, $response, $args))
      return $check;

    $results = ORM::for_table('tests')
      ->raw_query('SELECT features.*, feature_results.implements FROM features
        LEFT JOIN feature_results ON features.number = feature_results.feature_num
          AND feature_results.endpoint_id = :endpoint_id
        WHERE features.group = "server"
        ORDER BY features.number', ['endpoint_id'=>$this->endpoint->id])
      ->find_many();

    if(is_logged_in())
      $this->user = logged_in_user();

    $response->getBody()->write(view('view-implementation-report', [
      'title' => 'Micropub Rocks!',
      'endpoint' => $this->endpoint,
      'user' => $this->user,
      'results' => $results,
    ]));
    return $response;
  }

  public function save_report(ServerRequestInterface $request, ResponseInterface $response) {
    if($check = $this->_check_permissions($request, $response, 'body'))
      return $check;

    $params = $request->getParsedBody();

    if($this->endpoint) {
      foreach($params['data'] as $k=>$v) {
        $this->endpoint->{$k} = $v;
      }
      $this->endpoint->save();
    } elseif($this->client) {

    }

    return new JsonResponse([
      'result' => 'ok',
    ], 200);
  }

  public function publish_report(ServerRequestInterface $request, ResponseInterface $response) {
    if($check = $this->_check_permissions($request, $response, 'body'))
      return $check;

    $params = $request->getParsedBody();

    if($this->endpoint) {
      if($this->endpoint->share_token == '') {
        $this->endpoint->share_token = random_string(20);
        $this->endpoint->save();
      }
      $token = $this->endpoint->share_token;
    } elseif($this->client) {

    }

    return new JsonResponse([
      'result' => 'ok',
      'location' => Config::$base . 'implementation-report/'.$params['type'].'/'.$params['id'].'/'.$token
    ], 200);
  }


  private function _server_report(ServerRequestInterface $request, ResponseInterface $response, $args) {
    session_setup();

    if(array_key_exists('token', $args)) {
      $this->endpoint = ORM::for_table('micropub_endpoints')
        ->where('share_token', $args['token'])
        ->where('id', $args['id'])
        ->find_one();

      if(!$this->endpoint) {
        return $response->withHeader('Location', '/?error=404')->withStatus(302);
      }

    } else {
      if(!is_logged_in()) {
        return login_required($response);
      }

      $this->user = logged_in_user();

      $this->endpoint = ORM::for_table('micropub_endpoints')
        ->where('user_id', $this->user->id)
        ->where('id', $args['id'])
        ->find_one();
    }
    
    return null;
  }

  private function _client_report(ServerRequestInterface $request, ResponseInterface $response, $args) {
    session_setup();

    if(array_key_exists('token', $args)) {
      $this->client = ORM::for_table('micropub_clients')
        ->where('share_token', $args['token'])
        ->where('id', $args['id'])
        ->find_one();

      if(!$this->client) {
        return $response->withHeader('Location', '/?error=404')->withStatus(302);
      }

    } else {
      if(!is_logged_in()) {
        return login_required($response);
      }

      $this->user = logged_in_user();

      $this->client = ORM::for_table('micropub_clients')
        ->where('user_id', $this->user->id)
        ->where('id', $args['id'])
        ->find_one();
    }
    
    return null;
  }

  private function _check_permissions(&$request, &$response, $source='query') {
    session_setup();

    if(!is_logged_in()) {
      return login_required($response);
    }

    if($source == 'body')
      $params = $request->getParsedBody();
    else
      $params = $request->getQueryParams();
    
    $this->user = logged_in_user();

    // Verify an endpoint is specified and the user has permission to access it
    if(!isset($params['id']) || !isset($params['type']) || !in_array($params['type'], ['client','server']))
      return $response->withHeader('Location', '/dashboard?error='.$params['type'])->withStatus(302);

    if($params['type'] == 'server') {
      $this->endpoint = ORM::for_table('micropub_endpoints')
        ->where('user_id', $this->user->id)
        ->where('id', $params['id'])
        ->find_one();

      if(!$this->endpoint)
        return $response->withHeader('Location', '/dashboard?error=404')->withStatus(302);
    } else {
      $this->client = ORM::for_table('micropub_clients')
        ->where('user_id', $this->user->id)
        ->where('id', $params['id'])
        ->find_one();

      if(!$this->client)
        return $response->withHeader('Location', '/dashboard?error=404')->withStatus(302);
    }

    return null;    
  }

  public static function store_server_feature($endpoint_id, $feature_num, $implements, $test_id) {
    $result = ORM::for_table('feature_results')
      ->where('endpoint_id', $endpoint_id)
      ->where('feature_num', $feature_num)
      ->find_one();

    if(!$result) {
      // New result
      $result = ORM::for_table('feature_results')->create();
      $result->endpoint_id = $endpoint_id;
      $result->feature_num = $feature_num;
      $result->created_at = date('Y-m-d H:i:s');
      $result->implements = $implements;
    } else {
      // Updating a result, only set to fail (-1) if the new result is from the same test
      if($implements == 1) {
        $result->implements = $implements;
      } else {
        if($result->source_test_id == $test_id) {
          $result->implements = $implements;
        }
      }
    }

    $result->source_test_id = $test_id;
    $result->updated_at = date('Y-m-d H:i:s');
    $result->save();

    // Publish this result on the streaming API
    streaming_publish('endpoint-'.$endpoint_id, [
      'feature' => $feature_num,
      'implements' => $implements
    ]);
  }

  public static function store_client_feature($client_id, $feature_num, $implements, $test_id) {
    $result = ORM::for_table('feature_results')
      ->where('client_id', $client_id)
      ->where('feature_num', $feature_num)
      ->find_one();

    if(!$result) {
      // New result
      $result = ORM::for_table('feature_results')->create();
      $result->client_id = $client_id;
      $result->feature_num = $feature_num;
      $result->created_at = date('Y-m-d H:i:s');
      $result->implements = $implements;
    } else {
      // Updating a result, only set to fail (-1) if the new result is from the same test
      if($implements == 1) {
        $result->implements = $implements;
      } else {
        if($result->source_test_id == $test_id) {
          $result->implements = $implements;
        }
      }
    }

    $result->source_test_id = $test_id;
    $result->updated_at = date('Y-m-d H:i:s');
    $result->save();

    // Publish this result on the streaming API
    streaming_publish('client-'.$client_id, [
      'feature' => $feature_num,
      'implements' => $implements
    ]);
  }

  public function store_result(ServerRequestInterface $request, ResponseInterface $response) {
    if($check = $this->_check_permissions($request, $response, 'body'))
      return $check;

    $params = $request->getParsedBody();

    $col = $params['type'] == 'server' ? 'endpoint_id' : 'client_id';
    $id = $params['id'];

    self::store_server_feature($id, $params['feature_num'], $params['implements'], $params['source_test']);

    return new JsonResponse([
      'result' => 'ok'
    ], 200);
  }

  public function show_reports(ServerRequestInterface $request, ResponseInterface $response) {
    session_setup();

    $endpoints = [];
    $results = [];
    $features = [];

    $query = ORM::for_table('micropub_endpoints')
      ->where_not_null('share_token')
      ->find_many();
    foreach($query as $q) {
      $endpoints[] = $q;

      $endpoint_results = ORM::for_table('tests')
        ->raw_query('SELECT features.*, feature_results.implements FROM features
          LEFT JOIN feature_results ON features.number = feature_results.feature_num
            AND feature_results.endpoint_id = :endpoint_id
          WHERE features.group = "server"
          ORDER BY features.number', ['endpoint_id'=>$q->id])
        ->find_many();

      foreach($endpoint_results as $endpoint_result) {
        if(!array_key_exists($endpoint_result->number, $results))
          $results[$endpoint_result->number] = [];
        $results[$endpoint_result->number][$q->id] = $endpoint_result->implements;
      }
    }

    $query = ORM::for_table('features')
      ->where('group', 'server')
      ->order_by_asc('number')
      ->find_many();
    foreach($query as $q) {
      $features[$q->number] = $q->description;
    }

    $response->getBody()->write(view('reports/servers', [
      'title' => 'Server Reports - Micropub Rocks!',
      'endpoints' => $endpoints,
      'results' => $results,
      'features' => $features
    ]));
    return $response;
  }

  public function server_report_summary(ServerRequestInterface $request, ResponseInterface $response) {
    session_setup();

    $response->getBody()->write(view('reports/server-summary', [
      'title' => 'Server Report Summary - Micropub Rocks!',
    ]));
    return $response;
  }

  public function redirect_server(ServerRequestInterface $request, ResponseInterface $response, $args) {
    $path = $args['id'];
    if(isset($args['token']))
      $path .= '/' . $args['token'];
    return $response->withHeader('Location', '/implementation-reports/servers/'.$path)->withStatus(301);
  }

}
