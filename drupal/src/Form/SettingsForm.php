<?php

declare(strict_types=1);

namespace Drupal\collaborate\Form;

use Drupal\collaborate\DependencyInjection\Options;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Janalis\Collaborate\Bridge\Bridge;
use Janalis\Collaborate\CollaborateBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The options a site sets from its administration: who sees the others and how, shared drafts, refreshed listings
 * and notifications. The bundle's configuration tree validates them; the services read them once the container is
 * rebuilt, which saving does.
 */
final class SettingsForm extends ConfigFormBase
{
    private const string CONFIG = 'collaborate.settings';

    public function getFormId(): string
    {
        return 'collaborate_settings';
    }

    /** @return list<string> */
    protected function getEditableConfigNames(): array
    {
        return [self::CONFIG];
    }

    /** @param array<array-key, mixed> $form */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config(self::CONFIG);
        $form['cursors'] = [
            '#type' => 'details',
            '#title' => $this->t('Presence and cursors'),
            '#open' => true,
            '#tree' => true,
            'enabled' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Show the pointers, carets and fields of the others'),
                '#default_value' => $config->get('cursors.enabled') ?? true,
            ],
            'presence' => [
                '#type' => 'radios',
                '#title' => $this->t('The others in the header'),
                '#options' => ['avatars' => $this->t('Avatars'), 'menu' => $this->t('A menu')],
                '#default_value' => $config->get('cursors.presence') ?? 'avatars',
            ],
            'max_avatars' => [
                '#type' => 'number',
                '#title' => $this->t('Avatars shown before a counter'),
                '#min' => 1,
                '#default_value' => $config->get('cursors.max_avatars') ?? 5,
            ],
            'idle' => [
                '#type' => 'number',
                '#title' => $this->t('Seconds without input after which a user shows as idle'),
                '#description' => $this->t('0 for never.'),
                '#min' => 0,
                '#default_value' => $config->get('cursors.idle') ?? 0,
            ],
            'markers' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Mark the records the others are on'),
                '#default_value' => $config->get('cursors.markers') ?? false,
            ],
            'regions' => [
                '#type' => 'textarea',
                '#title' => $this->t('Regions of a page'),
                '#description' => $this->t(
                    'One per line, <code>name: selector</code>, the first one an element is in naming it. Empty for the administration theme\'s: @regions',
                    [
                        '@regions' => implode(', ', array_keys(Bridge::Drupal->regions())),
                    ],
                ),
                '#default_value' => self::lines($config->get('cursors.regions') ?? []),
            ],
        ];
        $form['drafts'] = [
            '#type' => 'details',
            '#title' => $this->t('Shared drafts'),
            '#open' => true,
            '#tree' => true,
            'enabled' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Share the drafts of entity forms'),
                '#default_value' => $config->get('drafts.enabled') ?? true,
            ],
            'rich_text' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Share CKEditor 5 fields as rich text'),
                '#default_value' => $config->get('drafts.rich_text') ?? true,
            ],
        ];
        $form['updates'] = [
            '#type' => 'details',
            '#title' => $this->t('Refreshed listings'),
            '#open' => true,
            '#tree' => true,
            'enabled' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Refresh listings and records shown when a save elsewhere changes them'),
                '#default_value' => $config->get('updates.enabled') ?? true,
            ],
        ];
        $form['notifications'] = [
            '#type' => 'details',
            '#title' => $this->t('Notifications'),
            '#open' => true,
            '#tree' => true,
            'enabled' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Show the notification bell'),
                '#default_value' => $config->get('notifications.enabled') ?? false,
            ],
            'web_push' => [
                '#type' => 'checkbox',
                '#title' => $this->t('Send web push to browsers with no page open'),
                '#description' => $this->t(
                    'Needs the VAPID keys in settings.php: <code>drush collaborate:push:setup</code>.',
                ),
                '#default_value' => $config->get('notifications.web_push') ?? false,
            ],
        ];

        return parent::buildForm($form, $form_state);
    }

    /** The options as the bundle's configuration tree reads them. */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);
        $regions = self::regions((string) $form_state->getValue(['cursors', 'regions']));
        if (null === $regions) {
            $form_state->setErrorByName(
                'cursors][regions',
                $this->t('Write one region per line, as <code>name: selector</code>.'),
            );

            return;
        }
        $extension = new CollaborateBundle()->getContainerExtension();
        $configuration = $extension?->getConfiguration(
            [],
            new \Symfony\Component\DependencyInjection\ContainerBuilder(),
        );
        if (null === $configuration) {
            return;
        }
        try {
            new Processor()->processConfiguration($configuration, [Options::bundle($this->values($form_state), [])]);
        } catch (InvalidConfigurationException $error) {
            $form_state->setErrorByName('', $error->getMessage());
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $config = $this->config(self::CONFIG);
        foreach ($this->values($form_state) as $key => $value) {
            $config->set($key, $value);
        }
        $config->save();
        // The bundle's services read their options when the container is built.
        \Drupal::service('kernel')->invalidateContainer();
        parent::submitForm($form, $form_state);
    }

    /** @return array<string, mixed> */
    private function values(FormStateInterface $state): array
    {
        $cursors = (array) $state->getValue('cursors');
        $drafts = (array) $state->getValue('drafts');
        $notifications = (array) $state->getValue('notifications');

        return [
            'cursors' => [
                'enabled' => (bool) ($cursors['enabled'] ?? true),
                'presence' => (string) ($cursors['presence'] ?? 'avatars'),
                'max_avatars' => (int) ($cursors['max_avatars'] ?? 5),
                'idle' => (int) ($cursors['idle'] ?? 0),
                'markers' => (bool) ($cursors['markers'] ?? false),
                'regions' => self::regions((string) ($cursors['regions'] ?? '')) ?? [],
            ],
            'drafts' => [
                'enabled' => (bool) ($drafts['enabled'] ?? true),
                'rich_text' => (bool) ($drafts['rich_text'] ?? true),
            ],
            'updates' => ['enabled' => (bool) $state->getValue(['updates', 'enabled'])],
            'notifications' => [
                'enabled' => (bool) ($notifications['enabled'] ?? false),
                'web_push' => (bool) ($notifications['web_push'] ?? false),
            ],
        ];
    }

    /** @return array<string, string>|null */
    private static function regions(string $lines): ?array
    {
        $regions = [];
        foreach (preg_split('/\R/', trim($lines)) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }
            if (1 !== preg_match('/^\s*([a-z][a-z0-9-]*)\s*:\s*(.+?)\s*$/', $line, $match)) {
                return null;
            }
            $regions[$match[1]] = $match[2];
        }

        return $regions;
    }

    /** @param array<string, string> $regions */
    private static function lines(array $regions): string
    {
        return implode("\n", array_map(
            static fn(string $name, string $selector): string => $name . ': ' . $selector,
            array_keys($regions),
            $regions,
        ));
    }
}
