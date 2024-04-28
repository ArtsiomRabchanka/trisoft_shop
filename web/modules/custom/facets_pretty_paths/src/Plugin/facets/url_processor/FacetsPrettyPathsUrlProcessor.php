<?php

namespace Drupal\facets_pretty_paths\Plugin\facets\url_processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\facets\Entity\Facet;
use Drupal\facets\Event\UrlCreated;
use Drupal\facets\FacetInterface;
use Drupal\facets\UrlProcessor\UrlProcessorPluginBase;
use Drupal\facets_pretty_paths\PrettyPathsActiveFilters;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Pretty paths URL processor.
 *
 * @FacetsUrlProcessor(
 *   id = "facets_pretty_paths",
 *   label = @Translation("Pretty paths"),
 *   description = @Translation("Pretty paths uses slashes as separator, e.g. /brand/drupal/color/blue"),
 * )
 */
class FacetsPrettyPathsUrlProcessor extends UrlProcessorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current_route_match service.
   *
   * @var \Drupal\Core\Routing\ResettableStackedRouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The service responsible for determining the active filters.
   *
   * @var \Drupal\facets_pretty_paths\PrettyPathsActiveFilters
   */
  protected $activeFiltersService;

  /**
   * Records active children/parents to deselect with "use hierarchy" option.
   *
   * @var array
   */
  protected $activeHierarchy = [];

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs FacetsPrettyPathsUrlProcessor object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A request object for the current request.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match service.
   * @param \Drupal\facets_pretty_paths\PrettyPathsActiveFilters $activeFilters
   *   The active filters service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   *
   * @throws \Drupal\facets\Exception\InvalidProcessorException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Request $request, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $routeMatch, PrettyPathsActiveFilters $activeFilters, EventDispatcherInterface $eventDispatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $request, $entity_type_manager);
    $this->routeMatch = $routeMatch;
    $this->activeFiltersService = $activeFilters;
    $this->initializeActiveFilters();
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      version_compare(\Drupal::VERSION, '9.3', '>=') ? $container->get('request_stack')->getMainRequest() : $container->get('request_stack')->getMasterRequest(),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('facets_pretty_paths.active_filters'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildUrls(FacetInterface $facet, array $results) {

    // No results are found for this facet, so don't try to create urls.
    if (empty($results)) {
      return [];
    }

    $current_path = rtrim($this->request->getPathInfo(), '/');
    $facet_source_path = $facet->getFacetSource()->getPath();
    $facet_source_path_length = strlen($facet_source_path);
    $filters = '';
    if(substr($current_path, 0, $facet_source_path_length) === $facet_source_path){
      $filters = substr($current_path, $facet_source_path_length);
    }
    $coder_plugin_manager = \Drupal::service('plugin.manager.facets_pretty_paths.coder');
    $coder_id = $facet->getThirdPartySetting('facets_pretty_paths', 'coder', 'default_coder');
    $coder = $coder_plugin_manager->createInstance($coder_id, ['facet' => $facet]);

    /** @var \Drupal\facets\Result\ResultInterface $result */
    foreach ($results as &$result) {
      $raw_value = $result->getRawValue();
      $encoded_value = $coder->encode($raw_value);

      $filters_current_result = $filters;
      $filter_key = $facet->getUrlAlias();
      // If the value is active, remove the filter string from the parameters.
      if ($result->isActive()) {
        $filters_current_result = str_replace('/' . $filter_key . '/' . $encoded_value, '', $filters_current_result);
        if ($facet->getEnableParentWhenChildGetsDisabled() && $facet->getUseHierarchy()) {
          // Enable parent id again if exists.
          $parent_ids = $facet->getHierarchyInstance()->getParentIds($raw_value);
          if (isset($parent_ids[0]) && $parent_ids[0]) {
            $filters_current_result .= '/' . $filter_key . '/' . $coder->encode($parent_ids[0]);
          }
        }
      }
      // If the value is not active, add the filter string.
      else {
        $filters_current_result .= '/' . $filter_key . '/' . $encoded_value;

        if ($facet->getUseHierarchy()) {
          // If hierarchy is active, unset parent trail and every child when
          // building the enable-link to ensure those are not enabled anymore.
          $parent_ids = $facet->getHierarchyInstance()->getParentIds($raw_value);
          $child_ids = $facet->getHierarchyInstance()->getNestedChildIds($raw_value);
          $parents_and_child_ids = array_merge($parent_ids, $child_ids);
          foreach ($parents_and_child_ids as $id) {
            $filters_current_result = str_replace('/' . $filter_key . '/' . $coder->encode($id) . '/', '/', $filters_current_result);
          }
        }
        // Exclude currently active results from the filter params if we are in
        // the show_only_one_result mode.
        if ($facet->getShowOnlyOneResult()) {
          foreach ($results as $result2) {
            if ($result2->isActive()) {
              $active_filter_string = '/' . $filter_key . '/' . $coder->encode($result2->getRawValue());
              $filters_current_result = str_replace($active_filter_string, '', $filters_current_result);
            }
          }
        }
      }

      $url = Url::fromUri('base:' . $facet->getFacetSource()->getPath() . $filters_current_result);

      // First get the current list of get parameters.
      $get_params = $this->request->query;
      // When adding/removing a filter the number of pages may have changed,
      // possibly resulting in an invalid page parameter.
      if ($get_params->has('page')) {
        $current_page = $get_params->get('page');
        $get_params->remove('page');
      }
      $url->setOption('query', $get_params->all());
      $result->setUrl($url);
      // Restore page parameter again. See https://www.drupal.org/node/2726455.
      if (isset($current_page)) {
       $get_params->set('page', $current_page);
      }
    }

    return $results;
  }

  /**
   * Sorts an array with weight and name values.
   *
   * It sorts first by weight, then by the alias of the facet item value.
   *
   * @param array $pretty_paths
   *   The values to sort.
   *
   * @return array
   *   The sorted values.
   */
  public function sortByWeightAndName(array $pretty_paths) {
    array_multisort(array_column($pretty_paths, 'weight'), SORT_ASC,
      array_column($pretty_paths, 'name'), SORT_ASC,
      array_column($pretty_paths, 'pretty_path_alias'), SORT_ASC, $pretty_paths);

    return $pretty_paths;
  }

  /**
   * Initializes the active filters from the url.
   *
   * Get all the filters that are active by checking the request url and store
   * them in activeFilters which is an array where key is the facet id and value
   * is an array of raw values.
   */
  protected function initializeActiveFilters() {
    $path = $this->request->getPathInfo();
    $filters = ltrim($path, '/');
    $parts = explode('/', $filters);
    
    if(count($parts) % 2 !== 0){
      // Our key/value combination should always be even. If uneven, we just
      // assume that the first string is not part of the filters, and remove
      // it. This can occur when an url lives in the same path as our facet
      // source, e.g. /search/overview where /search is the facet source path.
      array_shift($parts);
    }
    $key = '';
    foreach ($parts as $index => $part) {
      if ($index % 2 == 0) {
        $key = $part;
      }
      else {
        if (!isset($this->activeFilters[$key])) {
          $this->activeFilters[$key] = [$part];
        }
        else {
          $this->activeFilters[$key][] = $part;
        }
      }
    }
  }

}
