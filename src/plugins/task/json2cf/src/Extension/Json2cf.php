<?php

/**
 * @copyright   Dimitris Grammatikogiannis
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Dgrammatiko\Plugin\Task\Json2cf\Extension;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/**
 * Task plugin with routines to change the offline status of the site. These routines can be used to control planned
 */
final class Json2cf extends CMSPlugin implements SubscriberInterface
{
  use DatabaseAwareTrait;
  use TaskPluginTrait;

  protected $logger;
  protected const TASKS_MAP = [
    'plg_task_json2cf' => [
      'langConstPrefix' => 'PLG_TASK_JSON2CF',
      'form'            => 'json2cf',
      'method'          => 'doit',
    ],
  ];

  public static function getSubscribedEvents(): array
  {
    return [
      'onTaskOptionsList'    => 'advertiseRoutines',
      'onExecuteTask'        => 'standardRoutineHandler',
      'onContentPrepareForm' => 'enhanceTaskItemForm',
    ];
  }

  public function doit(ExecuteTaskEvent $event): int
  {
    Log::addLogger(
      [
        'format'    => '{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}',
        'text_file' => 'social-brussels-sync.php',
      ],
      Log::ALL,
      [ 'msg-error' ]
    );

    $params        = $event->getArgument('params');

    if (empty($params->baseURL)) return false;
    if (empty($params->profile)) return false;
    if ((int) $params->categories < 1) return false;

    try {
      $profile = json_decode(file_get_contents(__DIR__ . '/../../jsons/' . $params->profile));
    } catch (\Exception $e) {
      return false;
    }

    if (!$profile) return false;

    $comContent    = $this->getApplication()->bootComponent('com_content')->getMVCFactory();
    $articlesModel = $comContent->createModel('Articles', 'Administrator', ['ignore_request' => true]);

    $articlesModel->setState('params', new Registry());
    $articlesModel->setState('filter.category_id', $params->categories);

    $articles = (array) $articlesModel->getItems();

    foreach ($articles as $article) {
      $rawdata             = null;
      $relatedCustomFields = FieldsHelper::getFields('com_content.article', $article);

      if (count($relatedCustomFields) === 0) {
        Log::add('No Fields, check your setup', Log::WARNING, 'msg-error');
        continue;
      }

      $fieldsData               = [];
      $fieldsData['com_fields'] = [];

      // Get the fields
      if (!empty($relatedCustomFields)) {
          foreach ($relatedCustomFields as $field) {
              $fieldsData['com_fields'][$field->name] = $field->rawvalue;
          }
      }

      if (!isset($fieldsData['com_fields']['fetch-url-id'])) continue;

      $baseUrl = str_replace('{{id}}', $fieldsData['com_fields']['fetch-url-id'], $params->baseURL);

      try {
        $rawdata = HttpFactory::getHttp()->get($baseUrl);
      } catch (\RuntimeException $e) {
        continue;
      }

      if ($rawdata === null || $rawdata->code !== 200) {
        Log::add('The URL: ' . $baseUrl . ' was not reachable > ' . $article->id . ' > ' . $article->title, Log::WARNING, 'msg-error');
        continue;
      }

      try {
        $data = json_decode($rawdata->body, true);
      } catch (\Exception $e) {
        Log::add('The JSON is not valid ' . $e->getMessage() . ' for ' . $baseUrl, Log::WARNING, 'msg-error');
        continue;
      }

      if (!$data) {
        Log::add('The JSON is not valid for ' . $baseUrl, Log::WARNING, 'msg-error');
        continue;
      }

      $this->updateArticle($profile, $article, $data, $fieldsData);
    }

    return Status::OK;
  }

  private function updateArticle($profile, $article, $fetchedData, $fieldsData): void
  {
    $hasChanges = false;

    // Get actuial article data
    $existingData = $this->getApplication()->bootComponent('com_content')->getMVCFactory()->createTable('Article', 'Administrator', []);
    $existingData->load($article->id);

    foreach($profile as $rule) {
      if (!in_array($rule->fieldName, array_keys($fieldsData['com_fields']))) {
        continue;
      }
      if (!str_contains($rule->external, '.')) {
        if (isset($fetchedData[$rule->external]) && isset($fieldsData['com_fields'][$rule->fieldName])) {
          if ($fieldsData['com_fields'][$rule->fieldName] !== $fetchedData[$rule->external]) {
            $hasChanges = true;
            $fieldsData['com_fields'][$rule->fieldName] = self::validate($fetchedData[$rule->external], $rule->as);
          }
        }
      } else {
        // destruct the fieldname
      }
    }

    if ($hasChanges) {
      // Set the modified date
      $existingData->modified = (new Date())->toSql();

      // Set the tags to success
      $existingData->newTags = [ 2 ];

      // save the article
      $existingData->store($existingData->id);
      (new TagsHelper)->postStoreProcess($existingData, $existingData->newTags, true);

      // trigger an Event
      $this->getApplication()->triggerEvent('onContentAfterSave', ['com_content.article', &$existingData, false, $fieldsData]);
    } else {
      // change the tag if something else
      // Set the tags to success
      $existingData->newTags = [ 4 ];
      $existingData->store($existingData->id);

      (new TagsHelper)->postStoreProcess($existingData, $existingData->newTags, true);
    }
   }

  private static function validate($value, $as): string
  {
    switch ($as) {
    case 'date':
    case 'string':
        return InputFilter::getInstance()->clean($value, 'string');
        break;
    case 'float':
        return InputFilter::getInstance()->clean($value, 'float');
        break;
    }

    return InputFilter::getInstance()->clean($value, 'string');
  }

  // J3 had a Custom Field of Type REPEATABLE. J4 has a Custom Field of Type SUBFORM. In the database the content is a bit different:
  // J3 : {"emailfr-repeatable0":{"email":"test1@test.com"},"emailfr-repeatable1":{"email":"test2@test.com"}} where emailfr was the Name of the Custom Field
  // J4 : {"row0":{"field6":"mailto:test1@test.com"},"row1":{"field6":"mailto:test2@test.com"}} where field6 refers to the fact that we have selected the CF with ID 6
  public function getRepeat(array $array, string $fieldId): string
  {
    $ix      = 0;
    $results = [];

    foreach ($array as $elem) {
      $item                     = [];
      $item['field' . $fieldId] = "mailto:" . $elem;
      $results['row' . $ix]     = $item;
      ++$ix;
    }

    return (string) json_encode($results);
  }
}
