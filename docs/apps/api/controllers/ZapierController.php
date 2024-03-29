<?php defined('MW_PATH')||exit('No direct script access allowed');
error_reporting(E_ALL);
ini_set('display_errors',1);

/**
 * AccountController
 *
 * Handles the actions for account related tasks
 *
 * @package MailWizz EMA
 * @author Serban George Cristian <cristian.serban@mailwizz.com>
 * @link http://www.mailwizz.com/
 * @copyright 2013-2015 MailWizz EMA (http://www.mailwizz.com)
 * @license http://www.mailwizz.com/license/
 * @since 1.0
 */
class ZapierController extends Controller
{

    /**
     * Default action, allowing to update the account
     */


    public $user_id;

    public function actionAuth()
    {

        $headers = apache_request_headers();
        $User = CustomerApiKey::model()->findUserByPublicKey($headers['X_MW_PUBLIC_KEY']);

        if(empty($User))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','No user found.')
            ),400);
        }

        $this->user_id = $User->customer_id;

        if($User->private!=$headers['X_MW_PRIVATE_KEY'])
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A private API Key is required.')
            ),400);
        }

        if(empty($User->public))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A public API Key is required.')
            ),400);
        }

        Logger::addProgress('(Zapier AUTH) Authorized Connection '.print_r($User,true),'(Zapier AUTH) Authorized Connection');

        return $this->renderJson(array(
                'status' => 'success'
            )
            ,200);
    }

    /*
     * This method returns the List Names to Zapier
     *
     */

    public function actionLists()
    {

        $headers = apache_request_headers();
        $User = CustomerApiKey::model()->findUserByPublicKey($headers['X_MW_PUBLIC_KEY']);

        $this->user_id = $User->customer_id;

        if($User->private!=$headers['X_MW_PRIVATE_KEY'])
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A private API Key is required.')
            ),400);
        }

        if(empty($User->public))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A public API Key is required.')
            ),400);
        }
        $lists = array("lists" => Lists::model()->getCustomerListsZapier($this->user_id));

        Logger::addProgress('(Zapier) ActionLists '.print_r($lists,true),'(Zapier) ActionLists');

        return $this->renderJson($lists,200);

    }

    /*
     * This method returns a list of subscribers to Zapier when the polling URL is hit. We may need to limit this
     * to emails added in the last hour?
     *
     */
    public function actionReturn()
    {

        $headers = apache_request_headers();
        $User = CustomerApiKey::model()->findUserByPublicKey($headers['X_MW_PUBLIC_KEY']);

        Logger::addProgress('(Zapier) actionReturn '.print_r($User,true),'(Zapier) ActionReturn');


        $this->user_id = $User->customer_id;

        if($User->private!=$headers['X_MW_PRIVATE_KEY'])
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A private API Key is required.')
            ),400);
        }

        if(empty($User->public))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A public API Key is required.')
            ),400);
        }


        $list_uid = $_GET['list_id'];
        $request = Yii::app()->request;

        $criteria = new CDbCriteria();
        $criteria->compare('list_uid',$list_uid);
        $criteria->compare('customer_id',$this->user_id);
        $criteria->addNotInCondition('status',array(Lists::STATUS_PENDING_DELETE));
        $list = Lists::model()->find($criteria);

        Logger::addProgress('(Zapier) actionReturn Empty List'.print_r($list,true),'(Zapier) ActionReturn Empty List');

        if(empty($list))
        {
            Logger::addPWarning('(Zapier) actionReturn Empty List'.print_r($list,true),'(Zapier) ActionReturn Empty List');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $criteria = new CDbCriteria();
        $criteria->compare('list_id',$list->list_id);
        $fields = ListField::model()->findAll($criteria);

        if(empty($fields))
        {
            Logger::addPWarning('(Zapier) actionReturn Empty Fields'.print_r($list,true),'(Zapier) ActionReturn Empty Fields');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not have any custom field defined.')
            ),404);
        }

        $perPage = (int)$request->getQuery('per_page',10);
        $page = (int)$request->getQuery('page',1);

        $maxPerPage = 50;
        $minPerPage = 10;

        if($perPage<$minPerPage)
        {
            $perPage = $minPerPage;
        }

        if($perPage>$maxPerPage)
        {
            $perPage = $maxPerPage;
        }

        if($page<1)
        {
            $page = 1;
        }

        $data = array(
            'count' => null,
            'total_pages' => null,
            'current_page' => null,
            'next_page' => null,
            'prev_page' => null,
            'records' => array(),
        );

        $criteria = new CDbCriteria();
        $criteria->select = 't.subscriber_id, t.subscriber_uid, t.status, t.source, t.ip_address';
        $criteria->compare('t.list_id',(int)$list->list_id);

        $count = ListSubscriber::model()->count($criteria);

        if($count==0)
        {
            return $this->renderJson(array(
                'status' => 'success',
                'data' => $data
            ),200);
        }

        $totalPages = ceil($count/$perPage);

        $data['count'] = $count;
        $data['current_page'] = $page;
        $data['next_page'] = $page<$totalPages?$page+1:null;
        $data['prev_page'] = $page>1?$page-1:null;
        $data['total_pages'] = $totalPages;

        $criteria->order = 't.date_added DESC';
        $criteria->limit = $perPage;
        $criteria->offset = ($page-1)*$perPage;

        $subscribers = ListSubscriber::model()->findAll($criteria);

        foreach($subscribers as $subscriber)
        {
            $record = array('id' => null); // keep this first!
            foreach($fields as $field)
            {
                $valueModel = ListFieldValue::model()->findByAttributes(array(
                    'field_id' => $field->field_id,
                    'subscriber_id' => $subscriber->subscriber_id,
                ));
                $record[strtolower($field->tag)] = !empty($valueModel->value)?$valueModel->value:null;
            }

            $record['id'] = $subscriber->subscriber_uid;
            $record['status'] = $subscriber->status;
            $record['source'] = $subscriber->source;
            $record['ip_address'] = $subscriber->ip_address;

            $data['records'][] = $record;
        }

        Logger::addProgress('(Zapier) actionReturn Subscribers USER ID '.$this->user_id.' '.print_r($data['records'],true),'(Zapier) ActionReturn Subscribers');

        return $this->renderJson(array(
            'subscribers' => $data['records']
        ),200);

    }

    /*
     * This method creates a subscriber when a post is recieved from Zapier
     *
     */
    public function actionIndex()
    {

        $request = Yii::app()->request;
        $data = json_decode(file_get_contents('php://input'));

        $headers = apache_request_headers();
        $list_uid = $data->{'LISTID'};

        $this->user_id = CustomerApiKey::model()->findUserByPublicKey($headers['X_MW_PUBLIC_KEY']);

        if(empty($this->user_id))
        {
            Logger::addWarning('(Zapier) actionIndex No User '.print_r($list_uid,true),'(Zapier) ActionReturn No User');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A public API Key is required.')
            ),400);
        }

        if(!$request->isPostRequest)
        {
            Logger::addWarning('(Zapier) actionIndex Not Post Request '.print_r($list_uid,true),'(Zapier) ActionReturn Not Post Request');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Only POST requests allowed for this endpoint.')
            ),400);
        }

        $email = $data->{'EMAIL'};

        if(empty($data->{'LNAME'}))
        {
            $data->{'LNAME'} = 'not sent';
        }

        if(empty($data->{'FNAME'}))
        {
            $data->{'FNAME'} = 'not sent';
        }

        Logger::addProgress('(Zapier) actionIndex Data '.print_r($data,true),'(Zapier) ActionReturn Data');

        if(empty($email))
        {

            Logger::addWarning('(Zapier) actionIndex Not Email '.print_r($data,true),'(Zapier) ActionReturn No Email');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide the subscriber email address.')
            ),422);
        }

        $validator = new CEmailValidator();
        $validator->allowEmpty = false;
        if(Yii::app()->options->get('system.common.dns_email_check',false))
        {
            $validator->checkMX = CommonHelper::functionExists('checkdnsrr');
            $validator->checkPort
                = CommonHelper::functionExists('dns_get_record')&&CommonHelper::functionExists('fsockopen');
        }

        if(!$validator->validateValue($email))
        {
            Logger::addWarning('(Zapier) actionIndex Email Failed Validator '.print_r($validator,true),'(Zapier) ActionReturn Email Failed Validator');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide a valid email address.')
            ),422);
        }

        if(!($list = $this->loadListByUid($list_uid)))
        {
            Logger::addWarning('(Zapier) actionIndex No List Found '.print_r($list,true),'(Zapier) ActionReturn No List Found');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $customer = $list->customer;
        $maxSubscribersPerList = (int)$customer->getGroupOption('lists.max_subscribers_per_list',-1);
        $maxSubscribers = (int)$customer->getGroupOption('lists.max_subscribers',-1);

        if($maxSubscribers>-1||$maxSubscribersPerList>-1)
        {
            $criteria = new CDbCriteria();
            $criteria->select = 'COUNT(DISTINCT(t.email)) as counter';

            if($maxSubscribers>-1&&($listsIds = $customer->getAllListsIds()))
            {
                $criteria->addInCondition('t.list_id',$listsIds);
                $totalSubscribersCount = ListSubscriber::model()->count($criteria);
                if($totalSubscribersCount>=$maxSubscribers)
                {
                    Logger::addWarning('(Zapier) actionIndex The maximum number of allowed subscribers has been reached. '.print_r($totalSubscribersCount,true),'(Zapier) ActionReturn The maximum number of allowed subscribers has been reached.');

                    return $this->renderJson(array(
                        'status' => 'error',
                        'error' => Yii::t('lists','The maximum number of allowed subscribers has been reached.')
                    ),409);
                }
            }

            if($maxSubscribersPerList>-1)
            {
                $criteria->compare('t.list_id',(int)$list->list_id);
                $listSubscribersCount = ListSubscriber::model()->count($criteria);
                if($listSubscribersCount>=$maxSubscribersPerList)
                {
                    Logger::addWarning('(Zapier) actionIndex The maximum number of allowed subscribers for this list has been reached. '.print_r($maxSubscribersPerList,true),'(Zapier) ActionReturn The maximum number of allowed subscribers for this list has been reached');

                    return $this->renderJson(array(
                        'status' => 'error',
                        'error' => Yii::t('lists','The maximum number of allowed subscribers for this list has been reached.')
                    ),409);
                }
            }
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id' => (int)$list->list_id,
            'email' => $email,
        ));

        Logger::addProgress('(Zapier) actionIndex Subscriber. '.print_r($subscriber,true),'(Zapier) ActionReturn Subscriber');

        if(!empty($subscriber))
        {
            Logger::addWarning('(Zapier) actionIndex The subscriber already exists in this list. ','(Zapier) ActionReturn The subscriber already exists in this list.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscriber already exists in this list.')
            ),409);
        }

        $subscriber = new ListSubscriber();
        $subscriber->list_id = $list->list_id;
        $subscriber->email = $email;
        $subscriber->source = ListSubscriber::SOURCE_API;
        $subscriber->ip_address = $request->getServer('HTTP_MW_REMOTE_ADDR',$request->getServer('REMOTE_ADDR'));

        Logger::addProgress('(Zapier) actionIndex New Subscriber. '.print_r($subscriber,true),'(Zapier) ActionReturn New Subscriber');


        if($list->opt_in==Lists::OPT_IN_SINGLE)
        {
            $subscriber->status = ListSubscriber::STATUS_CONFIRMED;
        }
        else
        {
            $subscriber->status = ListSubscriber::STATUS_UNCONFIRMED;
        }

        $blacklisted = $subscriber->getIsBlacklisted();
        if(!empty($blacklisted))
        {
            Logger::addWarning('(Zapier) actionIndex This email address is blacklisted. '.print_r($blacklisted,true),'(Zapier) ActionReturn This email address is blacklisted.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','This email address is blacklisted.')
            ),409);
        }

        $fields = ListField::model()->findAllByAttributes(array(
            'list_id' => $list->list_id,
        ));

        if(empty($fields))
        {
            Logger::addProgress('(Zapier) actionIndex The subscribers list does not have any custom field defined. '.print_r($fields,true),'(Zapier) ActionReturn The subscribers list does not have any custom field defined.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not have any custom field defined.')
            ),404);
        }

        $errors = array();
        foreach($fields as $field)
        {
            $value = $data->{$field->tag};
            if($field->required==ListField::TEXT_YES&&empty($value))
            {
                $errors[$field->tag]
                    = Yii::t('api','The field {field} is required by the list but it has not been provided!',array(
                    '{field}' => $field->tag
                ));
            }

            // note here to remove when we will support multiple values
            if(!empty($value)&&!is_string($value))
            {
                $errors[$field->tag]
                    = Yii::t('api','The field {field} contains multiple values, which is not supported right now!',array(
                    '{field}' => $field->tag
                ));
            }
        }

        if(!empty($errors))
        {
            Logger::addError('(Zapier) actionIndex Errors. '.print_r($errors,true),'(Zapier) ActionReturn Errors.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => $errors,
            ),422);
        }

        // since 1.3.5.7
        $details = (array)$request->getPut('details',array());
        if(!empty($details))
        {
            if(!empty($details['status'])&&in_array($details['status'],array_keys($subscriber->getStatusesList())))
            {
                $subscriber->status = $details['status'];
            }
            if(!empty($details['ip_address'])&&filter_var($details['ip_address'],FILTER_VALIDATE_IP))
            {
                $subscriber->ip_address = $details['ip_address'];
            }
            if(!empty($details['source'])&&in_array($details['source'],array_keys($subscriber->getSourcesList())))
            {
                $subscriber->source = $details['source'];
            }
        }

        if(!$subscriber->save())
        {
            Logger::addError('(Zapier) actionIndex Unable to save the subscriber! '.print_r($subscriber,true),'(Zapier) ActionReturn Unable to save the subscriber!.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Unable to save the subscriber!'),
            ),422);
        }

        $substr = CommonHelper::functionExists('mb_substr')?'mb_substr':'substr';

        foreach($fields as $field)
        {
            $valueModel = new ListFieldValue();
            $valueModel->field_id = $field->field_id;
            $valueModel->subscriber_id = $subscriber->subscriber_id;
            $valueModel->value = substr($data->{$field->tag},0,255);
            $valueModel->save();
        }

        if($list->opt_in==Lists::OPT_IN_DOUBLE)
        {
            Logger::addProgress('(Zapier) actionIndex Send Confirmation Email. '.print_r($fields,true),'(Zapier) ActionReturn Send Confirmation Email.');

            $this->sendSubscribeConfirmationEmail($list,$subscriber);
        }
        else
        {
            Logger::addProgress('(Zapier) actionIndex Send Welcome Email. '.print_r($fields,true),'(Zapier) ActionReturn Send Welcome Email.');

            // since 1.3.5 - this should be expanded in future
            $subscriber->takeListSubscriberAction(ListSubscriberAction::ACTION_SUBSCRIBE);

            // since 1.3.5.4 - send the welcome email
            $this->sendSubscribeWelcomeEmail($list,$subscriber);
        }

        return $this->renderJson(array(
            'status' => 'success',
        ),201);
    }

    /*
     * This method is not used at this time and maybe deleted in the future
     *
     */
    public function actionUpdate($list_uid,$subscriber_uid)
    {

        $request = Yii::app()->request;

        if(!$request->isPutRequest)
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Only PUT requests allowed for this endpoint.')
            ),400);
        }

        $email = $request->getPut('EMAIL');
        if(empty($email))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide the subscriber email address.')
            ),422);
        }

        $validator = new CEmailValidator();
        $validator->allowEmpty = false;
        if(Yii::app()->options->get('system.common.dns_email_check',false))
        {
            $validator->checkMX = CommonHelper::functionExists('checkdnsrr');
            $validator->checkPort
                = CommonHelper::functionExists('dns_get_record')&&CommonHelper::functionExists('fsockopen');
        }

        if(!$validator->validateValue($email))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide a valid email address.')
            ),422);
        }

        if(!($list = $this->loadListByUid($list_uid)))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'subscriber_uid' => $subscriber_uid,
            'list_id' => $list->list_id,
        ));

        if(empty($subscriber))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscriber does not exist in this list.')
            ),409);
        }

        $fields = ListField::model()->findAllByAttributes(array(
            'list_id' => $list->list_id,
        ));

        if(empty($fields))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not have any custom field defined.')
            ),404);
        }

        $errors = array();
        foreach($fields as $field)
        {
            $value = $request->getPut($field->tag);
            if($field->required==ListField::TEXT_YES&&empty($value))
            {
                $errors[$field->tag]
                    = Yii::t('api','The field {field} is required by the list but it has not been provided!',array(
                    '{field}' => $field->tag
                ));
            }

            // note here to remove when we will support multiple values
            if(!empty($value)&&!is_string($value))
            {
                $errors[$field->tag]
                    = Yii::t('api','The field {field} contains multiple values, which is not supported right now!',array(
                    '{field}' => $field->tag
                ));
            }
        }

        if(!empty($errors))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => $errors,
            ),422);
        }

        $criteria = new CDbCriteria();
        $criteria->condition = 't.list_id = :lid AND t.email = :email AND t.subscriber_id != :sid';
        $criteria->params = array(
            ':lid' => $list->list_id,
            ':email' => $email,
            ':sid' => $subscriber->subscriber_id,
        );
        $duplicate = ListSubscriber::model()->find($criteria);
        if(!empty($duplicate))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Another subscriber with this email address already exists in this list.')
            ),409);
        }

        $subscriber->email = $email;
        $blacklisted = $subscriber->getIsBlacklisted();
        if(!empty($blacklisted))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','This email address is blacklisted.')
            ),409);
        }

        // since 1.3.5.7
        $details = (array)$request->getPut('details',array());
        if(!empty($details))
        {
            if(!empty($details['status'])&&in_array($details['status'],array_keys($subscriber->getStatusesList())))
            {
                $subscriber->status = $details['status'];
            }
            if(!empty($details['ip_address'])&&filter_var($details['ip_address'],FILTER_VALIDATE_IP))
            {
                $subscriber->ip_address = $details['ip_address'];
            }
            if(!empty($details['source'])&&in_array($details['source'],array_keys($subscriber->getSourcesList())))
            {
                $subscriber->source = $details['source'];
            }
        }

        if(!$subscriber->save())
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Unable to save the subscriber!'),
            ),422);
        }

        $substr = CommonHelper::functionExists('mb_substr')?'mb_substr':'substr';

        foreach($fields as $field)
        {

            $valueModel = ListFieldValue::model()->findByAttributes(array(
                'field_id' => $field->field_id,
                'subscriber_id' => $subscriber->subscriber_id,
            ));

            if(empty($valueModel))
            {
                $valueModel = new ListFieldValue();
                $valueModel->field_id = $field->field_id;
                $valueModel->subscriber_id = $subscriber->subscriber_id;
            }

            $valueModel->value = $substr($request->getPut($field->tag),0,255);
            $valueModel->save();
        }

        if($logAction = Yii::app()->user->getModel()->asa('logAction'))
        {
            $logAction->subscriberUpdated($subscriber);
        }

        return $this->renderJson(array(
            'status' => 'success',
        ),200);
    }

    /*
     * This method is used to unsubscribe a user when a request is recieved by Zapier
     *
     */
    public function actionUnsubscribe()
    {

        $headers = apache_request_headers();
        $User = CustomerApiKey::model()->findUserByPublicKey($headers['X_MW_PUBLIC_KEY']);

        if(empty($User))
        {
            Logger::addWarning('(Zapier) actionIndex No User. '.print_r($User,true),'(Zapier) ActionReturn No User.');

            return $this->renderJson(array(
                            'status' => 'error',
                            'error' => Yii::t('api','No User Found.')
                        ),400);
        }

        Logger::addProgress('(Zapier) actionIndex User. '.print_r($User,true),'(Zapier) ActionReturn User.');

        $this->user_id = $User->customer_id;

        if($User->private!=$headers['X_MW_PRIVATE_KEY'])
        {
            Logger::addWarning('(Zapier) actionIndex A private API Key is required. '.print_r($headers,true),'(Zapier) ActionReturn A private API Key is required.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A private API Key is required.')
            ),400);
        }

        if(empty($User->public))
        {
            Logger::addWarning('(Zapier) actionIndex A public API Key is required. '.print_r($headers,true),'(Zapier) ActionReturn A public API Key is required.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','A public API Key is required.')
            ),400);
        }


        if(!($list = $this->loadListByUid($_GET['list_id'])))
        {
            Logger::addWarning('(Zapier) actionIndex The subscribers list does not exist. '.print_r($list,true),'(Zapier) ActionReturn The subscribers list does not exist.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'email' => $_GET['email'],
            'list_id' => $list->list_id,
        ));

        Logger::addProgress('(Zapier) actionIndex Subscriber. '.print_r($subscriber,true),'(Zapier) ActionReturn Subscriber.');

        if(empty($subscriber))
        {
            Logger::addWarning('(Zapier) actionIndex The subscriber does not exist in this list. '.print_r($subscriber,true),'(Zapier) ActionReturn The subscriber does not exist in this list.');

            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscriber does not exist in this list.')
            ),404);
        }

        $subscriber->status = ListSubscriber::STATUS_UNSUBSCRIBED;
        $saved = $subscriber->save(false);

        // since 1.3.5 - this should be expanded in future
        if($saved)
        {
            $subscriber->takeListSubscriberAction(ListSubscriberAction::ACTION_UNSUBSCRIBE);
        }

        if($logAction = Yii::app()->user->getModel()->asa('logAction'))
        {
            $logAction->subscriberUnsubscribed($subscriber);
        }

        Logger::addProgress('(Zapier) actionIndex Subscriber Added. '.print_r($subscriber,true),'(Zapier) ActionReturn Subscriber Added.');


        return $this->renderJson(array(
            'status' => 'success',
        ),200);
    }

    /*
     * This method is not used at this time and maybe deleted in the future
     *
     */
    public function actionDelete($list_uid,$subscriber_uid)
    {

        $request = Yii::app()->request;

        if(!$request->isDeleteRequest)
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Only DELETE requests allowed for this endpoint.')
            ),400);
        }

        if(!($list = $this->loadListByUid($list_uid)))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'subscriber_uid' => $subscriber_uid,
            'list_id' => $list->list_id,
        ));

        if(empty($subscriber))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscriber does not exist in this list.')
            ),404);
        }

        $subscriber->delete();

        if($logAction = Yii::app()->user->getModel()->asa('logAction'))
        {
            $logAction->subscriberDeleted($subscriber);
        }

        return $this->renderJson(array(
            'status' => 'success',
        ),200);
    }

    /*
     * This method is not used at this time and maybe deleted in the future
     *
     */
    public function actionSearch_by_email($list_uid)
    {

        $request = Yii::app()->request;

        $email = $request->getQuery('EMAIL');
        if(empty($email))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide the subscriber email address.')
            ),422);
        }

        $validator = new CEmailValidator();
        $validator->allowEmpty = false;
        if(!$validator->validateValue($email))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','Please provide a valid email address.')
            ),422);
        }

        if(!($list = $this->loadListByUid($list_uid)))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscribers list does not exist.')
            ),404);
        }

        $subscriber = ListSubscriber::model()->findByAttributes(array(
            'list_id' => $list->list_id,
            'email' => $email,
        ));

        if(empty($subscriber))
        {
            return $this->renderJson(array(
                'status' => 'error',
                'error' => Yii::t('api','The subscriber does not exist in this list.')
            ),404);
        }

        return $this->renderJson(array(
            'status' => 'success',
            'data' => $subscriber->getAttributes(array('subscriber_uid','status')),
        ),200);
    }

    public function loadListByUid($list_uid)
    {

        $criteria = new CDbCriteria();
        $criteria->compare('list_uid',$list_uid);
        $criteria->addNotInCondition('status',array(Lists::STATUS_PENDING_DELETE));
        return Lists::model()->find($criteria);
    }

    public function loadUserByPublicKey($public_key)
    {

        return CustomerApiKey::model()->findUserByPublicKey($public_key);
    }

    /*
     * This method is not used at this time and maybe deleted in the future
     *
     */
    public function generateLastModified()
    {

        static $lastModified;

        if($lastModified!==null)
        {
            return $lastModified;
        }

        $request = Yii::app()->request;
        $row = array();

        if($this->action->id=='index')
        {

            $listUid = $request->getQuery('list_uid');
            $perPage = (int)$request->getQuery('per_page',10);
            $page = (int)$request->getQuery('page',1);

            $maxPerPage = 50;
            $minPerPage = 10;

            if($perPage<$minPerPage)
            {
                $perPage = $minPerPage;
            }

            if($perPage>$maxPerPage)
            {
                $perPage = $maxPerPage;
            }

            if($page<1)
            {
                $page = 1;
            }

            $list = Lists::model()->findByAttributes(array(
                'list_uid' => $listUid,
                'customer_id' => (int)Yii::app()->user->getId(),
            ));

            if(empty($list))
            {
                return $lastModified = parent::generateLastModified();
            }

            $limit = $perPage;
            $offset = ($page-1)*$perPage;

            $sql
                = '
                   SELECT AVG(t.last_updated) as `timestamp`
                   FROM (
                        SELECT `a`.`list_id`, `a`.`status`, UNIX_TIMESTAMP(`a`.`last_updated`) as `last_updated`
                        FROM `{{list_subscriber}}` `a`
                        WHERE `a`.`list_id` = :lid
                        ORDER BY a.`subscriber_id` DESC
                        LIMIT :l OFFSET :o
                   ) AS t
                   WHERE `t`.`list_id` = :lid
               ';

            $command = Yii::app()->getDb()->createCommand($sql);
            $command->bindValue(':lid',(int)$list->list_id,PDO::PARAM_INT);
            $command->bindValue(':l',(int)$limit,PDO::PARAM_INT);
            $command->bindValue(':o',(int)$offset,PDO::PARAM_INT);

            $row = $command->queryRow();

        }
        elseif($this->action->id=='view')
        {

            $listUid = $request->getQuery('list_uid');
            $subscriberUid = $request->getQuery('subscriber_uid');

            $list = Lists::model()->findByAttributes(array(
                'list_uid' => $listUid,
                'customer_id' => (int)Yii::app()->user->getId(),
            ));

            if(empty($list))
            {
                return $lastModified = parent::generateLastModified();
            }

            $subscriber = ListSubscriber::model()->findByAttributes(array(
                'subscriber_uid' => $subscriberUid,
                'list_id' => $list->list_id,
            ));

            if(!empty($subscriber))
            {
                $row['timestamp'] = strtotime($subscriber->last_updated);
            }
        }

        if(isset($row['timestamp']))
        {
            $timestamp = round($row['timestamp']);
            if(preg_match('/\.(\d+)/',$row['timestamp'],$matches))
            {
                $timestamp += (int)$matches[1];
            }
            return $lastModified = $timestamp;
        }

        return $lastModified = parent::generateLastModified();
    }

    protected function sendSubscribeConfirmationEmail($list,$subscriber)
    {

        if(!($server = DeliveryServer::pickServer(0,$list)))
        {
            return false;
        }

        $pageType = ListPageType::model()->findBySlug('subscribe-confirm-email');

        if(empty($pageType))
        {
            return false;
        }

        $page = ListPage::model()->findByAttributes(array(
            'list_id' => $list->list_id,
            'type_id' => $pageType->type_id
        ));

        $content = !empty($page->content)?$page->content:$pageType->content;
        $options = Yii::app()->options;

        $subscribeUrl = $options->get('system.urls.frontend_absolute_url');
        $subscribeUrl .= 'lists/'.$list->list_uid.'/confirm-subscribe/'.$subscriber->subscriber_uid;

        $searchReplace = array(
            '[LIST_NAME]' => $list->display_name,
            '[COMPANY_NAME]' => !empty($list->company)?$list->company->name:null,
            '[SUBSCRIBE_URL]' => $subscribeUrl,
            '[CURRENT_YEAR]' => date('Y'),
        );

        $content = str_replace(array_keys($searchReplace),array_values($searchReplace),$content);

        $params = array(
            'to' => $subscriber->email,
            'fromName' => $list->default->from_name,
            'subject' => Yii::t('list_subscribers','Please confirm your subscription'),
            'body' => $content,
        );

        Logger::addProgress('(Zapier) sendSubscribeConfirmationEmail Send Confirmation Email. '.print_r($params,true),'(Zapier) ActionReturn Send Confirmation Email.');


        $sent = false;
        for($i = 0;$i<3;++$i)
        {
            if(
            $sent = $server->setDeliveryFor(DeliveryServer::DELIVERY_FOR_LIST)->setDeliveryObject($list)
                ->sendEmail($params)
            )
            {
                break;
            }
            $server = DeliveryServer::pickServer($server->server_id,$list);
        }

        return $sent;
    }

    protected function sendSubscribeWelcomeEmail($list,$subscriber)
    {

        if($list->welcome_email!=Lists::TEXT_YES)
        {
            return;
        }

        $pageType = ListPageType::model()->findBySlug('welcome-email');
        if(!($server = DeliveryServer::pickServer(0,$list)))
        {
            $pageType = null;
        }

        if(empty($pageType))
        {
            return;
        }

        $page = ListPage::model()->findByAttributes(array(
            'list_id' => $list->list_id,
            'type_id' => $pageType->type_id
        ));

        $options = Yii::app()->options;
        $_content = !empty($page->content)?$page->content:$pageType->content;
        $updateProfileUrl
            = $options->get('system.urls.frontend_absolute_url').'lists/'.$list->list_uid.'/update-profile/'.$subscriber->subscriber_uid;
        $unsubscribeUrl
            = $options->get('system.urls.frontend_absolute_url').'lists/'.$list->list_uid.'/unsubscribe/'.$subscriber->subscriber_uid;
        $searchReplace = array(
            '[LIST_NAME]' => $list->display_name,
            '[COMPANY_NAME]' => !empty($list->company)?$list->company->name:null,
            '[UPDATE_PROFILE_URL]' => $updateProfileUrl,
            '[UNSUBSCRIBE_URL]' => $unsubscribeUrl,
            '[COMPANY_FULL_ADDRESS]' => !empty($list->company)?nl2br($list->company->getFormattedAddress()):null,
            '[CURRENT_YEAR]' => date('Y'),
        );
        $_content = str_replace(array_keys($searchReplace),array_values($searchReplace),$_content);

        $params = array(
            'to' => $subscriber->email,
            'fromName' => $list->default->from_name,
            'subject' => Yii::t('list_subscribers','Thank you for your subscription!'),
            'body' => $_content,
        );

        Logger::addProgress('(Zapier) sendSubscribeWelcomeEmail Send Welcome Email. '.print_r($params,true),'(Zapier) ActionReturn Send Welcome Email.');

        for($i = 0;$i<3;++$i)
        {
            if($server->setDeliveryFor(DeliveryServer::DELIVERY_FOR_LIST)->setDeliveryObject($list)->sendEmail($params))
            {
                break;
            }
            $server = DeliveryServer::pickServer($server->server_id,$list);
        }
    }

    /*
     * Test enopoint for chuck to check dns records.
     *
     */
    public function actionTest()
    {

        echo "domain:<br>";
        $domain = $_GET['domain'];
        echo $domain."<br>";
        $result = (array)dns_get_record($domain);
        print_r($result);
    }
}