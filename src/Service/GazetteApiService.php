<?php

namespace Drupal\gazette_notices\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for interacting with The Gazette API.
 */
class GazetteApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The base URL for The Gazette API.
   *
   * @var string
   */
  protected $baseUrl = 'https://www.thegazette.co.uk/all-notices/notice/data.json';

  /**
   * Constructs GazetteApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('gazette_notices');
  }

  /**
   * Fetches notices from The Gazette API.
   *
   * @param int $page
   *   The page number to fetch (1-based).
   *
   * @return array|null
   *   The API response data or NULL on failure.
   */
  public function getNotices(int $page = 1) {
    try {
      $options = [
        'query' => [
          'results-page' => $page,
        ],
        'timeout' => 30,
        'verify' => FALSE, // As mentioned in requirements for self-signed cert
      ];

      $response = $this->httpClient->request('GET', $this->baseUrl, $options);

      if ($response->getStatusCode() === 200) {
        return json_decode($response->getBody(), TRUE);
      }
      else {
        $this->logger->error('API request failed with status code: @code', [
          '@code' => $response->getStatusCode(),
        ]);
      }
    }
    catch (RequestException $e) {
      $this->logger->error('HTTP request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Unexpected error: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Parses the API response to extract notice data.
   *
   * @param array $data
   *   The raw API response data.
   *
   * @return array
   *   Parsed notice data with entries and pagination info.
   */
  public function parseNotices(array $data): array
  {
    $notices = [];
    $pagination = [
      'current_page' => 1,
      'total_pages' => 1,
      'total_results' => 0,
      'page_size' => 10,
    ];

    if (isset($data['entry']) && is_array($data['entry'])) {
      foreach ($data['entry'] as $entry) {
        $notices[] = [
          'id' => $entry['id'] ?? '',
          'title' => $entry['title'] ?? 'Untitled',
          'content' => $this->extractContent($entry['content'] ?? ''),
          'published' => $this->formatDate($entry['published'] ?? ''),
          'url' => $this->extractNoticeUrl($entry),
        ];
      }
    }

    // Extract pagination information
    if (isset($data['f:page-number'])) {
      $pagination['current_page'] = (int) $data['f:page-number'];
    }
    if (isset($data['f:total'])) {
      $pagination['total_results'] = (int) $data['f:total'];
    }
    if (isset($data['f:page-size'])) {
      $pagination['page_size'] = (int) $data['f:page-size'];
    }

    $pagination['total_pages'] = ceil($pagination['total_results'] / $pagination['page_size']);

    return [
      'notices' => $notices,
      'pagination' => $pagination,
    ];
  }

  /**
   * Extracts clean content from HTML.
   *
   * @param string $content
   *   The HTML content.
   *
   * @return string
   *   Cleaned content.
   */
  protected function extractContent(string $content): string
  {
    // Remove HTML tags but preserve basic structure
    $content = strip_tags($content, '<p><br><strong><em>');
    return trim($content);
  }

  /**
   * Formats the date string.
   *
   * @param string $date
   *   The date string from API.
   *
   * @return string
   *   Formatted date string.
   */
  protected function formatDate(string $date): string
  {
    try {
      $datetime = new \DateTime($date);
      return $datetime->format('j F Y');
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to parse date: @date', ['@date' => $date]);
      return $date;
    }
  }

  /**
   * Extracts the notice URL from entry links.
   *
   * @param array $entry
   *   The entry data.
   *
   * @return string
   *   The notice URL.
   */
  protected function extractNoticeUrl(array $entry): string
  {
    if (isset($entry['link']) && is_array($entry['link'])) {
      foreach ($entry['link'] as $link) {
        // Look for the main notice link (without @rel attribute or with @rel="self")
        if (is_array($link) && isset($link['@href'])) {
          if (!isset($link['@rel']) || $link['@rel'] === 'self') {
            return $link['@href'];
          }
        }
      }

      // Fallback to first link if no self link found
      if (isset($entry['link'][0]['@href'])) {
        return $entry['link'][0]['@href'];
      }
    }

    return '#';
  }

}
