<?php
namespace Drupal\cmesh_azure_pipeline\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ContentPushForm.
 */
class ContentPushForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
            'cmesh_azure_pipeline.contentpush',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'content_push_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('cmesh_azure_pipeline.contentpush');
        // get access token stored in Key module
        $azure_pipeline_url = $config->get('azure_pipeline_url');
        $access_token_id = $config->get('access_token');
        if ($access_token_id == null || $azure_pipeline_url == null) {
            $form['azure_pipeline_url'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Azure Pipeline URL'),
                '#default_value' => '',
            ];
            $form['access_token'] = [
                '#type' => 'key_select',
                '#title' => $this->t('Azure DevOps PAT'),
            ];
        }
        else {
            $form['result_table'] = [
                '#type' => 'table',
                '#caption' => 'Pipeline Runs',
                '#header' =>
                    array($this->t('Pipeline Name'),
                          $this->t('Run Name'), $this->t('State'),
                          $this->t('Result'), $this->t('Created'), $this->t('Finished')),
            ];

            try {
                $client = \Drupal::httpClient();
                $access_token = \Drupal::service('key.repository')->getKey($access_token_id)->getKeyValue();
                $resQA = $client->request('GET', $azure_pipeline_url, ['auth' => ['user', $access_token]]);

                if ($resQA->getStatusCode() == 200) {
                    $bodyQA = json_decode($resQA->getBody());
                    $merged = $bodyQA->value;
                    usort($merged, function($a, $b) { return strcmp($b->createdDate, $a->createdDate);});
                    $index = 0;
                    foreach ($merged as $item) {

                        $form['result_table'][$index]['pipeline_name'] = [
                            '#type' => 'item',
                            '#title' => $item->pipeline->name,
                        ];
                        $form['result_table'][$index]['run_name'] = [
                            '#type' => 'item',
                            '#title' => $item->name,
                        ];
                        $form['result_table'][$index]['state'] = [
                            '#type' => 'item',
                            '#title' => $item->state,
                        ];
                        $form['result_table'][$index]['result'] = [
                            '#type' => 'item',
                            '#title' => isset($item->result) ? $item->result : '',
                        ];
                        $form['result_table'][$index]['created'] = [
                            '#type' => 'item',
                            '#title' => isset($item->createdDate) ? \Drupal::service('date.formatter')->format(date_create($item->createdDate)->getTimestamp(), 'custom', 'Y-m-d h:i:s a') : '',
                        ];
                        $form['result_table'][$index]['finished'] = [
                            '#type' => 'item',
                            '#title' => isset($item->finishedDate) ? \Drupal::service('date.formatter')->format(date_create($item->finishedDate)->getTimestamp(), 'custom', 'Y-m-d h:i:s a') : '',
                        ];

                        $index++;
                        if ($index > 5) {
                            break;
                        }
                    }
                }

            } catch (Exception $e) {
                \Drupal::logger('cmesh_azure_pipeline')->error('bad response'.$e->getMessage());
            }

            $form['refresh_status'] = [
                '#type' => 'button',
                '#title' => $this->t('Refresh'),
                '#default_value' => $this->t('Refresh'),
            ];
            $current_user = \Drupal::currentUser();
            $options = [];
            if ($current_user->hasPermission('cmesh_azure_pipeline push_content_qa')) {
                $options[] = 'QA';
            }
            if ($current_user->hasPermission('cmesh_azure_pipeline push_content_prod')) {
                $options[] = 'Production';
            }

            $form['push_content'] = [
                '#type' => 'radios',
                '#title' => $this->t('Push content'),
                '#default_value' => 0,
                '#options' => $options,
            ];

            // add a collapsible block
            $form['azure_pipeline'] = [
                '#type' => 'details',
                '#open' => FALSE,
                '#title' => $this->t('Azure DevOps'),
            ];
            // add textfield to collapsible block
            $form['azure_pipeline']['azure_pipeline_url'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Azure Pipeline URL'),
                '#default_value' => $azure_pipeline_url,
            ];
            $form['azure_pipeline']['access_token'] = [
                '#type' => 'key_select',
                '#title' => $this->t('Azure DevOps PAT'),
                '#default_value' => $access_token_id,
            ];

        }
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        parent::submitForm($form, $form_state);
        $config = $this->config('cmesh_azure_pipeline.contentpush');
        $config->set('access_token', $form_state->getValue('access_token'))->save();
        $config->set('azure_pipeline_url', $form_state->getValue('azure_pipeline_url'))->save();

        if ($form_state->getValue('push_content') >= 0 ) {
            $client = \Drupal::httpClient();
            $body = $form_state->getValue('push_content') == 0 ? ['previewRun' => false,
                                                                  'resources' => [
                                                                      'repositories' => [
                                                                          'self' => ['refName' => 'refs/heads/main']
                                                                      ]
                                                                  ],
                                                                  'templateParameters' => [
                                                                      //'updateContentModel' => 'false',
                                                                      'environment' => 'qa'
                                                                  ]
            ] : ['previewRun' => false,
                 'resources' => [
                     'repositories' => [
                         'self' => ['refName' => 'refs/heads/main']
                     ]
                 ],
                 'templateParameters' => [
                     //                     'updateContentModel' => 'false',
                     'environment' => 'prod'
                 ]
            ];

            // get access token stored in Key module
            $access_token = \Drupal::service('key.repository')->getKey($form_state->getValue('access_token'))->getKeyValue();

            $res = $client->request('POST', $form_state->getValue('azure_pipeline_url'),
                                    [ 'headers' => ['Content-Type' => 'application/json'],
                                      'auth' => ['user', $access_token],
                                      'body' => json_encode($body),
                                      'http_errors' => false
                                    ]);
            if ($res->getStatusCode() == 200) {
                \Drupal::logger('cmesh_azure_pipeline')->info('Push content successful.');
            }
            else {
                \Drupal::logger('cmesh_azure_pipeline')->error($res->getBody());
            }
        }
        $config
            ->set('push_content_to_qa', $form_state->getValue('push_content_to_qa'))
            ->set('push_content_to_prod', $form_state->getValue('push_content_to_prod'))
            ->save();
    }
}
