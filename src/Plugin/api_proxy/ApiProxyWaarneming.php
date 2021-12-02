<?php

namespace Drupal\api_proxy_waarneming\Plugin\api_proxy;

use Drupal\api_proxy\Plugin\api_proxy\HttpApiCommonConfigs;
use Drupal\api_proxy\Plugin\HttpApiPluginBase;
use Drupal\Core\Form\SubformStateInterface;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Psr7;

iform_load_helpers(['data_entry_helper']);

/**
 * The Example API.
 *
 * @HttpApi(
 *   id = "waarneming",
 *   label = @Translation("Waarneming API"),
 *   description = @Translation("Proxies requests to the Waarneming API."),
 *   serviceUrl = "https://waarneming.nl/api",
 * )
 */
final class ApiProxyWaarneming extends HttpApiPluginBase {

  use HttpApiCommonConfigs;

  /**
   * {@inheritdoc}
   */
  public function addMoreConfigurationFormElements(array $form, SubformStateInterface $form_state): array {
    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#open' => FALSE,
      'client_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Client ID'),
        '#default_value' => $this->configuration['auth']['client_id'],
        '#description' => $this->t('An ID provided by the API administrators granting access.'),
      ],
      'email' => [
        '#type' => 'textfield',
        '#title' => $this->t('Email'),
        '#default_value' => $this->configuration['auth']['email'],
        '#description' => $this->t('Email address of an account on https://observation.org used for authentication.'),
      ],
      'password' => [
        '#type' => 'textfield',
        '#title' => $this->t('Password'),
        '#default_value' => $this->configuration['auth']['password'],
        '#description' => $this->t('Password of account used for authentication.'),
      ],
    ];
    $form['classify'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification'),
      '#open' => FALSE,
      'threshold' => [
        '#type' => 'textfield',
        '#title' => $this->t('Probability threshold'),
        '#default_value' => $this->configuration['classify']['threshold'] ?? 0.5,
        '#required' => TRUE,
        '#description' => $this->t('Threshold of classification probability, below which responses are ignored (0.0 to 1.0).'),
      ],
      'groups' => [
        '#type' => 'select',
        '#multiple' => TRUE,
        '#title' => $this->t('Species group filter'),
        '#options' => [
          1 => 'Birds',
          2 => 'Mammals',
          9 => 'Fish',
          3 => 'Reptiles and Amphibians',
          4 => 'Butterflies',
          8 => 'Moths',
          5 => 'Dragonflies',
          14 => 'Locusts and Crickets (Orthoptera)',
          15 => 'Bugs, Plant Lice and Cicadas',
          16 => 'Beetles',
          17 => 'Hymenoptera',
          18 => 'Diptera',
          6 => 'Insects (other)',
          13 => 'Other Arthropods (Arthropoda)',
          7 => 'Molluscs',
          20 => 'Other Invertebrates',
          10 => 'Plants',
          12 => 'Mosses and Lichens',
          19 => 'Algae, Seaweeds and other unicellular organisms',
          11 => 'Fungi',
          30 => 'Disturbances',
        ],
        '#default_value' => $this->configuration['classify']['groups'],
        '#description' => $this->t('List of species groups to which results are constrained.'),
      ],
      'suggestions' => [
        '#type' => 'textfield',
        '#title' => $this->t('Maximum number of suggestions'),
        '#default_value' => $this->configuration['classify']['suggestions'] ?? 1,
        '#description' => $this->t('The maximum number of classification suggestions to be returned.
        Note, the number of suggestions also depends on the probability threshold.
        If the threshold is >= 0.5, there can only be one suggestion as the 
        sum of probabilities is 1.0.'),
      ],
    ];
    $form['indicia'] = [
      '#type' => 'details',
      '#title' => $this->t('Indicia lookup'),
      '#open' => FALSE,
      'taxon_list_id' => [
        '#type' => 'textfield',
        '#title' => $this->t('Taxon list ID'),
        '#default_value' => $this->configuration['indicia']['taxon_list_id'],
        '#description' => $this->t('The ID of a taxon list to search for matches
        with the identified taxon. Allows the taxa_taxon_list_id to be added to
        the response.'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function calculateHeaders(array $headers): array {
    // Modify & add new headers.

    // Call the parent function to apply settings from the config page.
    $headers = parent::calculateHeaders($headers);
    // Remove content-type and content-length to ensure it is set correctly for
    // the post we will make rather than the one we received.
    // Remove origin otherwise we get a 404 response (possibly because CORS is
    // not supported).
    $headers = Psr7\_caseless_remove(
      ['Content-Type', 'content-length', 'origin'], $headers
    );

    // Request an auth token.
    $handle = curl_init('https://waarneming.nl/api/v1/oauth2/token/');
    curl_setopt($handle, CURLOPT_POSTFIELDS, [
      'client_id' => $this->configuration['auth']['client_id'],
      'grant_type' => 'password',
      'email' => $this->configuration['auth']['email'],
      'password' => $this->configuration['auth']['password'],
    ]);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
    $tokens = curl_exec($handle);
    if ($tokens !== FALSE) {
      $tokens = json_decode($tokens, TRUE);
      $headers['authorization'] = ['Bearer ' . $tokens['access_token']];
    }
    curl_close($handle);

    // @todo cache tokens for their lifetime.
    return $headers;
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessOutgoingRequestOptions(array $options): array {
    $postargs = [];

    // api_proxy module just handles POST data as a single body item.
    // https://docs.guzzlephp.org/en/6.5/request-options.html#body
    // We have to post the image file content to waarneming as
    // multipart/form-data.
    parse_str($options['body'], $postargs);
    if (isset($postargs['image'])) {
      $image_path = $postargs['image'];
      if (substr($image_path, 0, 4) == 'http') {
        // The image has to be obtained from a url.
        // Do a head request to determine the content-type.
        $handle = curl_init($image_path);
        curl_setopt($handle, CURLOPT_NOBODY, TRUE);
        curl_exec($handle);
        $content_type = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        curl_close($handle);

        // Open an interim file.
        $download_path = \data_entry_helper::getInterimImageFolder('fullpath');
        $download_path .= uniqid('api_proxy_waarneming_');
        switch ($content_type) {
          case 'image/png':
            $download_path .= '.png';
            break;

          case 'image/jpeg':
            $download_path .= '.jpg';
            break;

          default:
            throw new \InvalidArgumentException("Unhandled content type: $content_type.");
        }

        // Download image to interim file.
        $fp = fopen($download_path, 'w+');
        $handle = curl_init($image_path);
        curl_setopt($handle, CURLOPT_TIMEOUT, 50);
        curl_setopt($handle, CURLOPT_FILE, $fp);
        curl_exec($handle);
        curl_close($handle);
        fclose($fp);
        $image_path = $download_path;
      }
      else {
        // The image is stored locally
        // Determine full path to local file.
        $image_path =
          \data_entry_helper::getInterimImageFolder('fullpath') . $image_path;
      }

      // Replace the body option with a multipart option.
      $contents = fopen($image_path, 'r');
      if (!$contents) {
        throw new \InvalidArgumentException('The image could not be opened.');
      }
      $options['multipart'] = [
        [
          'name' => 'image',
          'contents' => $contents,
        ],
      ];
      unset($options['body']);
    }
    else {
      throw new \InvalidArgumentException('The POST body must contain an image
      parameter holding the location of the image to classify.');
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessOutgoing(Response $response): Response {
    // Modify the response from the API.

    $classification = json_decode($response->getContent(), TRUE);

    $connection = iform_get_connection_details(null);
    $readAuth = \data_entry_helper::get_read_auth(
      $connection['website_id'], $connection['password']
    );

    $data = [];
    foreach ($classification['predictions'] as $i => $prediction) {
      // Find predictions above the threshold.
      if ($prediction['probability'] >= $this->configuration['classify']['threshold']) {
        if (isset($this->configuration['classify']['groups'])) {
          // Find the species record matching the prediction
          // (The two arrays are not in the same order.)
          $found = FALSE;
          foreach ($classification['species'] as $species) {
            if ($species['scientific_name'] == $prediction['taxon']['name']) {
              $found = TRUE;
              break;
            }
          }
          // Skip predictions not in specified groups.
          if (!$found || !in_array($species['group'], $this->configuration['classify']['groups'])) {
            continue;
          }
        }

        $warehouse_data = [];
        if (!empty($this->configuration['indicia']['taxon_list_id'])) {
          // Perform lookup in Indicia species list.
          $getargs = [
            'searchQuery' => $species['scientific_name'],
            'taxon_list_id' => $this->configuration['indicia']['taxon_list_id'],
            'language' => 'lat',
            'preferred' => TRUE,
          ] + $readAuth;
          $url = $connection['base_url'] . 'index.php/services/data/taxa_search?';
          $url .= http_build_query($getargs);
          $session = curl_init($url);
          curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
          $taxa_search = curl_exec($session);
          if ($taxa_search !== FALSE) {
            // Request was successful.
            $taxa = json_decode($taxa_search, TRUE);
            if (count($taxa) > 0) {
              // Results are returned in priority order. Going to assume the
              // first is the correct match for now.
              $warehouse_data = [
                'taxon' => $taxa[0]['taxon'],
                'preferred_taxon' => $taxa[0]['preferred_taxon'],
                'authority' => $taxa[0]['authority'],
                'default_common_name' => $taxa[0]['default_common_name'],
                'taxa_taxon_list_id' => $taxa[0]['taxa_taxon_list_id'],
                'taxon_meaning_id' => $taxa[0]['taxon_meaning_id'],
              ];
            }
          }
        }

        // Add prediction to results.
        $data[] = [
          'classifier_id' => $prediction['taxon']['id'],
          'classifier_name' => $prediction['taxon']['name'],
          'probability' => $prediction['probability'],
          'group' => $species['group'],
        ] + $warehouse_data;

        // Exit loop if we have got enough suggestions.
        if (count($data) == $this->configuration['classify']['suggestions']) {
          break;
        }
      }
    }

    // Update response with filtered results.
    $response->setContent(json_encode($data));
    return $response;
  }

}
