<?php

namespace Drupal\gazette_notices\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\gazette_notices\Service\GazetteApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for displaying Gazette notices.
 */
class GazetteNoticesController extends ControllerBase {

  /**
   * The Gazette API client service.
   *
   * @var \Drupal\gazette_notices\Service\GazetteApiClient
   */
  protected $apiClient;

  /**
   * The pager manager service.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * Constructs a GazetteNoticesController object.
   *
   * @param \Drupal\gazette_notices\Service\GazetteApiService $api_client
   *   The API client service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager service.
   */
  public function __construct(GazetteApiService $api_client, PagerManagerInterface $pager_manager) {
    $this->apiClient = $api_client;
    $this->pagerManager = $pager_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('gazette_notices.api_client'),
      $container->get('pager.manager')
    );
  }

  /**
   * Displays a list of Gazette notices.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array.
   */
  public function list(Request $request) {
    // Get current page from query parameter (0-based for Drupal pager)
    $page = $request->query->get('page', 0);
    // Convert to 1-based for API
    $api_page = $page + 1;

    // Per page
    $per_page = 10;

    // Fetch data from API
    $data = $this->apiClient->getNotices($api_page);

    if ($data === NULL) {
      return [
        '#markup' => '<p>' . $this->t('Unable to fetch notices at this time. Please try again later.') . '</p>',
      ];
    }

    $parsed_data = $this->apiClient->parseNotices($data);
    $notices = $parsed_data['notices'];
    $pagination = $parsed_data['pagination'];

    // Create pager
    $pager = $this->pagerManager->createPager(
      $pagination['total_results'],
      $pagination['page_size']
    );


    // Build render array
    $build = [
      'notices_list' => [
        '#theme' => 'gazette_notices_list',
        '#notices' => $notices,
        '#cache' => [
          'max-age' => 300,
          'contexts' => ['url.query_args:page'],
        ],
      ],
      'pager' => [
        '#type' => 'pager',
        '#weight' => 10,
      ],
    ];
    return $build;

  }

}
