<?php

/**
 * @copyright   Mark
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\Json2CF\Extension;

use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Component\Content\Administrator\Model\ArticlesModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;
use Joomla\CMS\Helper\TagsHelper; // Marc

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Task plugin with routines to change the offline status of the site. These routines can be used to control planned
 */
final class Json2CF extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    protected $autoloadLanguage = true;
    protected $myParams;
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

    protected function doit(ExecuteTaskEvent $event): int
    {
        Log::addLogger(
            [
                'format'    => '{DATE}\t{TIME}\t{LEVEL}\t{CODE}\t{MESSAGE}',
                'text_file' => 'social-brussels-sync.php',
            ],
            Log::ALL,
            [ 'msg-error' ]
        );

        $this->myParams = $event->getArgument('params');
        $params     = new Registry();
        $categories = $this->myParams->categories;
        $articleModel   = new articlesModel(array('ignore_request' => true));

        $articleModel->setState('params', $params);
        $articleModel->setState('filter.category_id', $categories);

        $items = $articleModel->getItems();

        foreach ($items as $item) {

            $itemRelatedCustomFields = FieldsHelper::getFields('com_content.article', $item); // create a variable which will contain all CF of the current article
            if (!$itemRelatedCustomFields) {
                Log::add('No Fields, check your setup', Log::WARNING, 'msg-error');

                return false;
            }

            $itemRelatedCustomFieldsById = \Joomla\Utilities\ArrayHelper::pivot($itemRelatedCustomFields, 'id'); // we use a pivot on ID so that we can easily use the ID to access every CF by its ID
            // $itemRelatedCustomFieldsByName = \Joomla\Utilities\ArrayHelper::pivot($itemRelatedCustomFields, 'name'); // we use a pivot on NAME so that we can easily use the NAME to access every CF by its NAME
            $socialbrusselsId =  $itemRelatedCustomFieldsById[1]->rawvalue; // Custom Field with ID 1 is the one with the ID of social.brussels. Example: https://social.brussels/rest/organisation/18262 for JSON and https://social.brussels/organisation/18262 for HTML

            if ($socialbrusselsId) {

                // if (array_key_exists('id', $data)) { // https://social.brussels/rest/organisation/12582 n'existe pas par exemple. Gérer ce cas !!!! voir aussi comment avoir un log
                $url = 'https://social.brussels/rest/organisation/' . $socialbrusselsId; // path to the external JSON file

                try {
                    $rawdata = HttpFactory::getHttp()->get($url);
                } catch (\RuntimeException $e) {
                    $rawdata = null;
                    continue;
                }

                if (
                    $rawdata === null || $rawdata->code !== 200
                ) {
                    Log::add('The URL: ' . $url . ' was not reachable > ' . $item->id . ' > ' . $item->title, Log::WARNING, 'msg-error');
                    continue;
                }

                try {
                    $data = json_decode($rawdata->body, true); // decode the JSON feed
                } catch (\Exception $e) {
                    // Log the error
                    Log::add('The JSON is not valid ' . $e->getMessage() . ' for ' . $url, Log::WARNING, 'msg-error');
                    continue;
                }

                if (!$data) {
                    Log::add('The JSON is not valid for ' . $url, Log::WARNING, 'msg-error');
                    continue;
                }

                $articleModel ='';
                // $model = new FieldModel(array('ignore_request' => true));

                // // set the value of each Joomla Custom Field based on the corresponding value in the external JSON file
                // $model->setFieldValue(2, $item->id, $data['address']['streetNl'] ?? '');
                // $model->setFieldValue(3, $item->id, $data['address']['number'] ?? '');
                // $model->setFieldValue(4, $item->id, $data['address']['zipCode'] ?? '');
                // $model->setFieldValue(5, $item->id, $data['address']['municipalityFr'] ?? '');
                // $model->setFieldValue(7, $item->id, $this->getRepeat($data['emailFr'] ?? '', '6'));
                // $model->setFieldValue(9, $item->id, date("Y-m-d H:i:s"));
                // $model->setFieldValue(10, $item->id, $data['address']['lat'] . ',' . $data['address']['lon'] ?? '');

                // // https://docs.joomla.org/Tags_API_Guide
                // $item->tags = new TagsHelper;
                // $item->tags->getTagIds($item->id, 'com_content.article');
                // $model->setFieldValue(8, $item->id, $item->tags->getTagIds($item->id, 'com_content.article') ?? '');
            }
        }
        return TaskStatus::OK;
    }

    public function getRepeat(array $array, string $fieldId): string
    // J3 had a Custom Field of Type REPEATABLE. J4 has a Custom Field of Type SUBFORM. In the database the content is a bit different:
    // J3 : {"emailfr-repeatable0":{"email":"test1@test.com"},"emailfr-repeatable1":{"email":"test2@test.com"}} where emailfr was the Name of the Custom Field
    // J4 : {"row0":{"field6":"mailto:test1@test.com"},"row1":{"field6":"mailto:test2@test.com"}} where field6 refers to the fact that we have selected the CF with ID 6
    {
        $ix      = 0;
        $results = [];

        foreach ($array as $elem) {
            $item = [];
            $item['field' . $fieldId] = "mailto:" . $elem;
            $results['row' . $ix] = $item;
            ++$ix;
        }

        return (string) json_encode($results);
    }


    private function getRefTags(): array
    {

        $db = Factory::getDbo();

        // Load the tags.
        $query = $db->getQuery(true)
            ->select($db->quoteName('t.id'))
            ->from($db->quoteName('#__tags', 't'))
            ->join('INNER', $db->quoteName('#__contentitem_tag_map', 'm'), $db->quoteName('m.tag_id') . ' = ' . $db->quoteName('t.id'))
            ->where($db->quoteName('m.type_alias') . ' = :prefix')
            ->whereIn($db->quoteName('m.content_item_id'), $ids)
            ->bind(':prefix', $prefix);
        return [];
    }
}
