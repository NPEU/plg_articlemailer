<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.ArticleMailer
 *
 * @copyright   Copyright (C) NPEU 2026.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\Content\ArticleMailer\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;


/**
 * Sends an email to the configured user group when a new front-end article is created in the chosen category.
 */
final class ArticleMailer extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    /**
     * An internal flag whether plugin should listen any event.
     *
     * @var bool
     *
     * @since   4.3.0
     */
    #protected static $enabled = false;

    /**
     * Constructor
     *
     */
    /*public function __construct($subject, array $config = [], bool $enabled = true)
    {
        // The above enabled parameter was taken from the Guided Tour plugin but it always seems
        // to be false so I'm not sure where this param is passed from. Overriding it for now.
        $enabled = true;


        #$this->loadLanguage();
        $this->autoloadLanguage = $enabled;
        self::$enabled          = $enabled;

        parent::__construct($subject, $config);
    }*/

    /**
     * function for getSubscribedEvents : new Joomla 4 feature
     *
     * @return array
     *
     * @since   4.3.0
     */
    public static function getSubscribedEvents(): array
    {
        /*return self::$enabled ? [
            'onContentAfterSave' => 'onContentAfterSave',
        ] : [];*/
        return [
            'onContentAfterSave' => 'onContentAfterSave',
        ];
    }

    /**
     *
     *
     * @param   Form  $form  The form to be altered.
     * @param   mixed  $data  The associated data for the form.
     *
     * @return  boolean
     *
     * @since   <your version>
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if (!$this->getApplication() instanceof CMSApplicationInterface || !$this->getApplication()->isClient('site')) {
            return;
        }

        if (!$event->getIsNew()) {
            return;
        }

        if ($event->getContext() !== 'com_content.form') {
            return;
        }

        $item = $event->getItem();
        if (!is_object($item) || empty($item->id) || empty($item->catid)) {
            return;
        }

        if ((int) $this->params->get('require_published', 1) === 1 && (int) ($item->state ?? 0) !== 1) {
            return;
        }

        $targetCategoryId = (int) $this->params->get('target_category', 0);

        if ($targetCategoryId > 0 && (int) $item->catid !== $targetCategoryId) {
            return;
        }

        $recipients = $this->getRecipientEmails();
        if ($recipients === []) {
            return;
        }

        $author = $this->resolveAuthor($item);
        $category = $this->getCategoryTitle((int) $item->catid);
        $subject = $this->renderTemplate((string) $this->params->get('subject_template', ''), $item, $author, $category);
        $body = $this->renderTemplate((string) $this->params->get('body_template', ''), $item, $author, $category);

        $this->sendMessages($recipients, $subject, $body);
    }


    private function getCategoryTitle(int $categoryId): string
    {
        $category = $this->getCategoryRow($categoryId);

        return $category?->title ?? (string) $categoryId;
    }

    private function getCategoryRow(int $categoryId): ?object
    {
        static $cache = [];

        if (array_key_exists($categoryId, $cache)) {
            return $cache[$categoryId];
        }

        /** @var DatabaseInterface $db */
        $db = Factory::getDbo();
        $id = $categoryId;
        $extension = 'com_content';

        $query = $db->getQuery(true)
            ->select([$db->quoteName('title'), $db->quoteName('alias')])
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('extension') . ' = :extension')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER)
            ->bind(':extension', $extension);

        $db->setQuery($query);
        $cache[$categoryId] = $db->loadObject() ?: null;

        return $cache[$categoryId];
    }

    private function getRecipientEmails(): array
    {
        $groupId = (int) $this->params->get('recipient_group', 0);
        if ($groupId <= 0) {
            return [];
        }

        /** @var DatabaseInterface $db */
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('u.email'))
            ->from($db->quoteName('#__users', 'u'))
            ->innerJoin($db->quoteName('#__user_usergroup_map', 'm') . ' ON ' . $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
            ->innerJoin($db->quoteName('#__usergroups', 'g') . ' ON ' . $db->quoteName('g.id') . ' = ' . $db->quoteName('m.group_id'))
            ->where($db->quoteName('u.block') . ' = 0')
            ->where($db->quoteName('g.id') . ' = :groupId')
            ->bind(':groupId', $groupId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        $emails = array_values(array_filter(array_map('trim', (array) $db->loadColumn())));

        return array_values(array_unique($emails));
    }

    private function resolveAuthor(object $item): string
    {
        $createdBy = (int) ($item->created_by ?? 0);
        if ($createdBy > 0) {
            $user = Factory::getUser($createdBy);
            if (!empty($user->name)) {
                return (string) $user->name;
            }
        }

        $identity = $this->getApplication()->getIdentity();

        return !empty($identity->name) ? (string) $identity->name : Text::_('JUNKNOWN');
    }

    private function renderTemplate(string $template, object $item, string $author, string $category): string
    {
        $identity = $this->getApplication()->getIdentity();
        $link = $this->buildArticleLink($item);
        $excerpt = trim((string) ($item->introtext ?? ''));
        if ($excerpt === '' && isset($item->fulltext)) {
            $excerpt = trim((string) $item->fulltext);
        }
        $excerpt = trim(strip_tags(html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $replacements = [
            '{sitename}' => (string) Factory::getApplication()->get('sitename'),
            '{title}' => (string) ($item->title ?? ''),
            '{category}' => $category,
            '{author}' => $author,
            '{username}' => (string) ($identity->username ?? ''),
            '{id}' => (string) (int) $item->id,
            '{link}' => $link,
            '{excerpt}' => $excerpt,
            '{state}' => ((int) ($item->state ?? 0) === 1) ? 'Published' : 'Unpublished',
        ];

        return strtr($template, $replacements);
    }

    private function buildArticleLink(object $item): string
    {
        $articleId = (int) ($item->id ?? 0);
        $alias = trim((string) ($item->alias ?? ''));
        $catid = (int) ($item->catid ?? 0);

        if ($articleId <= 0) {
            return '';
        }

        $idSegment = (string) $articleId;
        if ($alias !== '') {
            $idSegment .= ':' . $alias;
        }

        $route = 'index.php?option=com_content&view=article&id=' . $idSegment;
        if ($catid > 0) {
            $route .= '&catid=' . $catid;
        }

        return Uri::root() . ltrim(Route::_($route), '/');
    }

    private function normalizeEmailBody(string $body): string
    {
        $body = str_replace(['\r\n', '\n', '\r'], ["\r\n", "\n", "\r"], $body);

        return rtrim($body);
    }

    private function sendMessages(array $recipients, string $subject, string $body): void
    {
        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
        $mailer->CharSet = 'UTF-8';

        $body = $this->normalizeEmailBody($body);

        foreach ($recipients as $recipient) {
            if ($recipient === '') {
                continue;
            }

            $message = clone $mailer;
            $message->addRecipient($recipient);
            $message->setSubject($subject);
            $message->setBody($body);

            try {
                $message->send();
            } catch (\Throwable $exception) {
                // Deliberately silent: article submission should not fail if email delivery does.
                // Site logging can be used to troubleshoot mail transport issues.
            }
        }
    }
}