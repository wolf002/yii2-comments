<?php

namespace yii2mod\comments\models;

use paulzi\adjacencyList\AdjacencyListBehavior;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii2mod\behaviors\PurifyBehavior;
use yii2mod\comments\traits\ModuleTrait;
use yii2mod\moderation\enums\Status;
use yii2mod\moderation\ModerationBehavior;
use yii2mod\moderation\ModerationQuery;
use common\helpers\HtmlUser;

/**
 * Class CommentModel
 *
 * @property int $id
 * @property string $entity
 * @property int $entityId
 * @property string $content
 * @property int $parentId
 * @property int $level
 * @property int $createdBy
 * @property int $updatedBy
 * @property string $relatedTo
 * @property string $url
 * @property int $status
 * @property int $createdAt
 * @property int $updatedAt
 *
 * @method ActiveRecord makeRoot()
 * @method ActiveRecord appendTo($node)
 * @method ActiveQuery getDescendants()
 * @method ModerationBehavior markRejected()
 * @method AdjacencyListBehavior deleteWithChildren()
 */
class CommentModel extends ActiveRecord
{
    use ModuleTrait;

    const SCENARIO_MODERATION = 'moderation';

    /**
     * @var null|array|ActiveRecord[] comment children
     */
    protected $children;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%comment}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['entity', 'entityId'], 'required'],
            ['content', 'required', 'message' => Yii::t('yii2mod.comments', 'Comment cannot be blank.')],
            [['content', 'entity', 'relatedTo', 'url'], 'string'],
            ['status', 'default', 'value' => Status::APPROVED],
            ['status', 'in', 'range' => Status::getConstantsByName()],
            ['level', 'default', 'value' => 1],
            ['parentId', 'validateParentID', 'except' => static::SCENARIO_MODERATION],
            [['entityId', 'parentId', 'status', 'level'], 'integer'],
        ];
    }

    /**
     * @param $attribute
     */
    public function validateParentID($attribute)
    {
        if (null !== $this->{$attribute}) {
            $parentCommentExist = static::find()
                ->approved()
                ->andWhere([
                    'id' => $this->{$attribute},
                    'entity' => $this->entity,
                    'entityId' => $this->entityId,
                ])
                ->exists();

            if (!$parentCommentExist) {
                $this->addError('content', Yii::t('yii2mod.comments', 'Oops, something went wrong. Please try again later.'));
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'blameable' => [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'createdBy',
                'updatedByAttribute' => 'updatedBy',
            ],
            'timestamp' => [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'createdAt',
                'updatedAtAttribute' => 'updatedAt',
            ],
            'purify' => [
                'class' => PurifyBehavior::class,
                'attributes' => ['content'],
                'config' => [
                    'HTML.SafeIframe' => true,
                    'URI.SafeIframeRegexp' => '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%',
                    'AutoFormat.Linkify' => true,
                    'HTML.TargetBlank' => true,
                    'HTML.Allowed' => 'a[href], iframe[src|width|height|frameborder], img[src]',
                ],
            ],
            'adjacencyList' => [
                'class' => AdjacencyListBehavior::class,
                'parentAttribute' => 'parentId',
                'sortable' => false,
            ],
            'moderation' => [
                'class' => ModerationBehavior::class,
                'moderatedByAttribute' => false,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('yii2mod.comments', 'ID'),
            'content' => Yii::t('yii2mod.comments', 'Content'),
            'entity' => Yii::t('yii2mod.comments', 'Entity'),
            'entityId' => Yii::t('yii2mod.comments', 'Entity ID'),
            'parentId' => Yii::t('yii2mod.comments', 'Parent ID'),
            'status' => Yii::t('yii2mod.comments', 'Status'),
            'level' => Yii::t('yii2mod.comments', 'Level'),
            'createdBy' => Yii::t('yii2mod.comments', 'Created by'),
            'updatedBy' => Yii::t('yii2mod.comments', 'Updated by'),
            'relatedTo' => Yii::t('yii2mod.comments', 'Related to'),
            'url' => Yii::t('yii2mod.comments', 'Url'),
            'createdAt' => Yii::t('yii2mod.comments', 'Created date'),
            'updatedAt' => Yii::t('yii2mod.comments', 'Updated date'),
        ];
    }

    /**
     * @return ModerationQuery
     */
    public static function find()
    {
        return new ModerationQuery(get_called_class());
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if (parent::beforeSave($insert)) {
            if ($this->parentId > 0 && $this->isNewRecord) {
                $parentNodeLevel = static::find()->select('level')->where(['id' => $this->parentId])->scalar();
                $this->level += $parentNodeLevel;
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if (!$insert) {
            if (array_key_exists('status', $changedAttributes)) {
                $this->beforeModeration();
            }
        }
    }

    /**
     * @return bool
     */
    public function saveComment()
    {
        if ($this->validate()) {
            if (empty($this->parentId)) {
                return $this->makeRoot()->save();
            } else {
                $parentComment = static::findOne(['id' => $this->parentId]);

                return $this->appendTo($parentComment)->save();
            }
        }

        return false;
    }

    /**
     * Author relation
     *
     * @return \yii\db\ActiveQuery
     */
    public function getAuthor()
    {
        return $this->hasOne($this->getModule()->userIdentityClass, ['id' => 'createdBy']);
    }

    /**
     * Get comments tree.
     *
     * @param string $entity
     * @param string $entityId
     * @param null $maxLevel
     *
     * @return array|ActiveRecord[]
     */
    public static function getTree($entity, $entityId, $maxLevel = null)
    {
        $addWhere = [
            'c.entityId' => $entityId,
            'c.entity' => $entity,
        ];
      
        $query = static::find()
            ->alias('c')
            ->approved()
            ->andWhere($addWhere)
            ->orderBy(['c.parentId' => SORT_ASC, 'c.createdAt' => SORT_ASC])
            ->with(['author']);

        if ($maxLevel > 0) {
            $query->andWhere(['<=', 'c.level', $maxLevel]);
        }

        $urlArray = parse_url($_SERVER['REQUEST_URI']);
        if($urlArray['path'] == '/user' || $urlArray['path'] == '/user/'.\Yii::$app->user->identity->id){
            $orWhere['c.url'] = '/user';
            $orWhere['c.url'] = '/user/'.\Yii::$app->user->identity->id;
            $query->orWhere($orWhere);
            $where = " (c.url = '/user/".\Yii::$app->user->identity->id."' OR c.url = '/user')  OR (c.url = '/user/news' AND c.createdBy = ".\Yii::$app->user->identity->id.") AND c.status = 1";// AND c.entityId = '".$entityId."' AND c.entity = '".$entity."' 
            $query->where($where); 
        } 
        
        if($urlArray['path'] == '/user/news'){ 
            $friends = \Yii::$app->db->createCommand('SELECT * FROM friend WHERE accept = 1 AND (to_user_id = '.\Yii::$app->user->identity->id.' OR from_user_id= '.\Yii::$app->user->identity->id.')')->queryAll(\PDO::FETCH_OBJ);
            
            $friendsId = [];
            foreach($friends as $f => $item){     
                if($item->to_user_id == \Yii::$app->user->identity->id){
                    $friendsId[] = $item->from_user_id;
                } 
                if($item->from_user_id == \Yii::$app->user->identity->id){
                    $friendsId[] = $item->to_user_id;
                }

            }

            $where = " (c.url = '/user/".\Yii::$app->user->identity->id."' AND c.createdBy != ".\Yii::$app->user->identity->id.") OR (c.createdBy IN (".implode($friendsId).") AND (c.url = '/user/news' OR c.url = '/user')) OR (c.url = '/user/news' AND c.createdBy = ".\Yii::$app->user->identity->id.") AND c.status = 1";// AND c.entityId = '".$entityId."' AND c.entity = '".$entity."' 
            $query->where($where);   
        }         
//echo $query->createCommand()->getRawSql();//die();
        $models = $query->all();

        if (!empty($models)) {
            $models = static::buildTree($models);
        }

        return $models;
    }

    /**
     * Build comments tree.
     *
     * @param array $data comments list
     * @param int $rootID
     *
     * @return array|ActiveRecord[]
     */
    protected static function buildTree(&$data, $rootID = 0)
    {
        $tree = [];

        foreach ($data as $id => $node) {
            if ($node->parentId == $rootID) {
                unset($data[$id]);
                $node->children = self::buildTree($data, $node->id);
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /**
     * @return array|null|ActiveRecord[]
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @param $value
     */
    public function setChildren($value)
    {
        $this->children = $value;
    }

    /**
     * @return bool
     */
    public function hasChildren()
    {
        return !empty($this->children);
    }

    /**
     * @return string
     */
    public function getPostedDate()
    {
        $datetime = new \DateTime();
        $today = strtotime($datetime->format("Y-m-d"));
        $datetime->sub(new \DateInterval('P1D'));
        $yesterday = strtotime($datetime->format("Y-m-d"));

        if($this->createdAt > (time() - 60*60*12)){
            return Yii::$app->formatter->asRelativeTime($this->createdAt);
        }else if($this->createdAt > $today){
            return 'Сегодня в '.Yii::$app->formatter->asTime($this->createdAt,'short');
        }else if($this->createdAt > $yesterday){
            return 'Вчера в '.Yii::$app->formatter->asTime($this->createdAt, 'short');
        }else{
            return Yii::$app->formatter->asDate($this->createdAt, 'd').' '.HtmlUser::getRusMonth(Yii::$app->formatter->asDate($this->createdAt, 'M')).' в '.Yii::$app->formatter->asTime($this->createdAt, 'short');
        }
        
    }

    /**
     * @return mixed
     */
    public function getAuthorName()
    {
        if ($this->author->hasMethod('getUsername')) {
            return $this->author->getUsername();
        }

        return $this->author->username;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return nl2br($this->content);
    }

    /**
     * Get avatar of the user
     *
     * @return string
     */
    public function getAvatar()
    {
        if ($this->author->hasMethod('getAvatar')) {
            return $this->author->getAvatar();
        }

        return 'http://www.gravatar.com/avatar?d=mm&f=y&s=50';
    }

    /**
     * Get list of all authors
     *
     * @return array
     */
    public static function getAuthors()
    {
        $query = static::find()
            ->alias('c')
            ->select(['c.createdBy', 'a.username'])
            ->joinWith('author a')
            ->groupBy(['c.createdBy', 'a.username'])
            ->orderBy('a.username')
            ->asArray()
            ->all();

        return ArrayHelper::map($query, 'createdBy', 'author.username');
    }

    /**
     * @return int
     */
    public function getCommentsCount()
    {
        return (int) static::find()
            ->approved()
            ->andWhere(['entity' => $this->entity, 'entityId' => $this->entityId])
            ->count();
    }

    /**
     * @return string
     */
    public function getAnchorUrl()
    {
        return "#comment-{$this->id}";
    }

    /**
     * @return null|string
     */
    public function getViewUrl()
    {
        if (!empty($this->url)) {
            return $this->url . $this->getAnchorUrl();
        }

        return null;
    }

    /**
     * Before moderation event
     *
     * @return bool
     */
    public function beforeModeration()
    {
        $descendantIds = ArrayHelper::getColumn($this->getDescendants()->asArray()->all(), 'id');

        if (!empty($descendantIds)) {
            static::updateAll(['status' => $this->status], ['id' => $descendantIds]);
        }

        return true;
    }
}
