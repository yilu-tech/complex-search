<?php

namespace Yilu\ComplexSearch;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ComplexSearch
{
    public $root;
    /**
     * @var \Illuminate\Database\Eloquent\Builder
     */
    public $query;

    public $action;

    protected $range = 2;

    protected $lang = 'fields';

    /**
     * @var RelationNode
     */
    protected $relations;

    protected $orderBy;

    protected $groupBy = array();

    protected $whereDef = array();

    protected $joinDef = array();

    protected $groupDef = array();

    protected $headers = array();

    protected $conditions = array();

    protected $customJoins = array();

    protected $hiddenFields = array();

    protected $customFields = array();

    protected $filterPreg = array();

    private $fields = array();

    private $joins = array();

    private $loop = array();

    private $request = array();

    private $sqlOperators = [
        'string' => ['=', '<>', 'like', 'not like'],
        'numeric' => ['=', '<>', '>', '>=', '<', '<=', 'in'],
        'boolean' => ['=', '<>'],
        'only' => ['=', '<>', 'in'],
        'json' => ['like', 'not like'],
        'date' => ['=', '<>', '>', '>=', '<', '<=', 'in']
    ];

    private $fun = ['add', 'mul', 'sub', 'div', 'sum', 'max', 'min', 'count', 'avg', 'if', 'date_format', 'round', 'cast', 'concat'];

    public function make($request)
    {
        $this->request = $request;

        $this->action = $this->input('action');

        if ($this->root) {
            if (is_string($this->root)) {
                $this->root = new $this->root;
            }

            $this->makeRelations($this->root, $this->range);
        }

        if ($this->action !== 'fields') return;

        if (method_exists($this, 'addOptions')) {
            $this->addOptions();
        }

        if (!$this->root) return;

        foreach ($this->conditions as $key => $condition) {
            if (!isset($condition['custom'])) {
                $this->bindCondition($this->find($key), $condition);
            }
        }
    }

    public function get()
    {
        if ($this->action === 'fields') {

            return $this->root ? [
                'fields' => array_merge($this->getFields(), $this->getConditions(true)),
                'headers' => $this->headers
            ] : [
                'conditions' => $this->getConditions(),
                'headers' => $this->headers
            ];

        } elseif ($this->action === 'query') {

            if (!$this->query) {

//                if (!$this->root) return [];

                $this->query = $this->query();
            }

            $this->addJoins();

            return $this->input('size') ? $this->query->paginate($this->input('size')) : $this->query->get();
        }

        return null;
    }

    public function query()
    {
        $this->query = $this->root->query();

        if ($this->action === 'query') {
            if (!count($this->input('fields', []))) {
                throw new \Exception('query fields null');
            }

            $this->addSelect($this->query, $this->getQueryFields());

            $this->addWhere($this->query, $this->getQueryConditions());

            $orderBy = $this->getOrderBy();
            if ($orderBy) {
                $this->addOrderBy($this->query, $orderBy);
            }

            $groupBy = $this->getGroupBy();
            if ($groupBy) {
                $this->addGroupBy($this->query, $groupBy);
            }
        }
        return $this->query;
    }

    public function getConditions($custom = false)
    {
        $conditions = array();
        foreach ($this->conditions as $key => $condition) {
            $condition['name'] = $key;
            if ($custom) {
                if (isset($condition['custom'])) {
                    $conditions[] = $condition;
                }
            } else {
                $conditions[] = $condition;
            }
        }
        return $conditions;
    }

    /**
     * @param $relation RelationNode
     * @return array
     */
    public function getFields($relation = null)
    {
        $relation = $relation ?: $this->relations;
        $fields = array();
        foreach ($relation->fields as $key => $value) {
            if ($this->validateField($value['name'], $relation)) {
                if (empty($value['label'])) {
                    $value['label'] = trans($this->lang . '.' . $relation->name . '.' . $key);
                }
                unset($value['_value']);
                unset($value['custom']);
                $fields[] = $value;
            }
        }
        foreach ($relation->childNodes as $key => $value) {
            $fields[] = [
                'label' => trans($this->lang . '.' . $relation->name . '.' . $key),
                'name' => $relation->path ? "{$relation->path}.{$key}.*" : $key . '.*',
                'children' => $this->getFields($value)
            ];
        }
        return $fields;
    }

    private function validateField($field, $relation)
    {
//        if ($relation->primaryKey === $field) {
//            return false;
//        }
        foreach ($this->filterPreg as $value) {
            if (preg_match("/{$value}/", $field)) {
                return false;
            }
        }
        return true;
    }

    public function addRelation($fk, $model, $table = 'root', $pk = 'id')
    {
        $relation = $table === 'root' ? $this->relations : $this->relations->findNode($table);

        if (!$relation) {
            throw new \Exception("table \"{$table}\" not exist");
        }

        if (!isset($relation->fields[$pk])) {
            throw new \Exception("foreignKey \"{$fk}\" not in \"{$table}\"");
        }

        $name = $model->getTable();

        $join = [[$model, "{$name}.{$pk}", "{$relation->name}.{$fk}"]];

        $node = new RelationNode($model, $join);

        $relation->addChild($name, $node);

        $node->fields = $this->getFieldsByModel($model, $node->path, $node->foreignKey);
    }

    public function field($field)
    {
        return $this->makRaw($this->find($field), false);
    }

    public function find($field)
    {
        $argv = explode('|', $field);
        if (count($argv) === 1) {
            $temp = explode('.', $field);
            $argv[1] = end($temp) === '*' ? $field : current($temp);
        }
        if (isset($this->fields[$field])) {
            return $this->fields[$field];
        }
        if ($argv[0] === '*') {
            $mix = $this->makeField('*', null, '*');
            $mix['table'] = $this->relations->name;
        } elseif (strpos($argv[0], '.') > 0) {
            $mix = $this->findByRelations($argv[0], $this->relations);
        } else {
            $mix = $this->findInRelations($argv[0], $this->relations);
        }
        if (!$mix) {
            throw new \Exception("field \"$field\" not exist");
        }
        if (isset($mix['model'])) {
            $this->setGroupBy($mix['model']);
            $mix = array_merge($mix['model']->fields[$mix['field']], [
                'table' => isset($mix['table']) ? $mix['table'] : $mix['model']->name
            ]);
        }
        $mix['rename'] = $argv[1];
        $this->fields[$field] = $mix;
        return $mix;
    }

    public function input($key, $default = null)
    {
        if (array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        if (!empty($this->{$key})) {
            return $this->{$key};
        }

        return $default;
    }

    public function getQueryConditions()
    {
        $conditions = $this->input('params', []);

        foreach ($conditions as &$condition) {
            if (is_array($condition[0])) {
                foreach ($condition as $key => &$item) {
                    $item = $this->formatWhere($item, $key ? 'or' : 'and');
                }
            } else {
                $condition = $this->formatWhere($condition);
            }
        }

        return $conditions;
    }

    protected function addWhere($query, $conditions)
    {
        foreach ($conditions as $condition) {
            if (isset($condition['fun'])) {
                if (isset($this->whereDef[$condition['argv'][0]])) {
                    $this->whereDef[$condition['argv'][0]]($query, $condition);
                    continue;
                }
                $query->{$condition['fun']}(...$condition['argv']);
            } else {
                $query->where(function ($query) use ($condition) {
                    $this->addWhere($query, $condition);
                });
            }
        }
        return $this;
    }

    public function getQueryFields()
    {
        $fields = $this->input('fields', []);
        $fields = array_unique(array_merge($fields, $this->hiddenFields));

        foreach ($fields as $field) {
            $node = $this->parseToMultiTree($this->find($field));
            if (is_array($node)) {
                $this->loop[0][$node[0]] = $node[1];
            } elseif ($node instanceof OperationNode) {
                $this->cutMultiTree($node);
            }
        }
        $fields = array();
        foreach ($this->loop[0] as $key => $value) {
            if ($value instanceof OperationNode) {
                $fields[] = $value->toString() . " as {$key}";
            } else {
                $fields[] = strpos($value, '.' . $key) ? $value : "{$value} as {$key}";
            }
        }
        return $fields;
    }

    protected function addSelect($query, $fields)
    {
        foreach ($fields as $field) {
            $query->addSelect(\DB::raw($field));
        }

        return $this;
    }

    public function getGroupBy($default = null)
    {
        $value = $this->input('groupBy', $default);
        if ($value && is_string($value)) {
            $value = $this->makRaw($this->find($value), false);
        }
        return $value;
    }

    protected function addGroupBy($query, $groups)
    {
        $query->groupBy(...$groups);

        return $this;
    }

    private function setGroupBy($model)
    {
        if (!$model->path) return;
        $paths = explode('.', $model->path);
        $groups = array();
        while (count($paths)) {
            $path = array_pop($paths);
            foreach ($this->groupDef as $key => $value) {
                if ($key === $path) {
                    array_unshift($groups, $this->field($value));
                } else if (is_int($key) && $value === $path) {
                    array_unshift($groups, $model->join[0][2]);
                }
            }
            $model = $model->parentNode;
        }
        $this->groupBy = array_unique(array_merge($this->groupBy, $groups));
    }

    public function getOrderBy($default = null)
    {
        $value = $this->input('orderBy', $default);
        if (!$value) {
            return $value;
        }

        $argv = explode(' ', $value);

        return [
            'field' => $this->field($argv[0]),
            'direction' => isset($argv[1]) ? $argv[1] : 'asc'
        ];
    }

    protected function addOrderBy($query, $orderBy)
    {
        $query->orderBy($orderBy['field'], $orderBy['direction']);

        return $this;
    }

    public function getJoins()
    {
        return $this->joins;
    }

    protected function addJoins()
    {
        foreach ($this->joins as $join) {
            if (isset($this->join[$join[0]])) {
                $this->join[$join[0]]($this->query);
            } else {
                $this->query->leftJoin($join[0], $join[1], '=', $join[2]);
            }
        }
    }

    private function bindCondition($field, $condition)
    {
        $relations = [$this->relations];

        while (count($relations)) {
            $relation = array_shift($relations);
            if ($relation->name === $field['table']) {
                $relation->fields[$field['name']] = array_merge($relation->fields[$field['name']], $condition);
                return;
            }
            if (count($relation->childNodes)) {
                $relations = array_merge($relations, array_values($relation->childNodes));
            }
        }
    }

    /**
     * @param $params
     * @param string $boolean
     * @return array
     * @throws \Exception
     */
    private function formatWhere($params, $boolean = 'and')
    {
        $field = $this->find($params[0]);
        if (!in_array($params[1], $this->sqlOperators[$field['itype']], true)) {
            throw new \Exception("where \"{$params[0]}\" operator \"{$params[1]}\" not exist");
        }

        if ($params[1] === 'like' || $params[1] === 'not like') {
            if ($params[2] === null) {
                $params[1] = $params[1] === 'like' ? '=' : '<>';
            } else {
                $params[2] = '%' . $params[2] . '%';
            }
        }
        $params[0] = $field['custom'] ? $field['rename'] : $this->makRaw($field, false);

        if ($params[1] === '=' && $params[2] === null) {
            $where = ['fun' => 'whereNull', 'argv' => [$params[0], $boolean]];
        } elseif ($params[1] === '<>' && $params[2] === null) {
            $where = ['fun' => 'whereNotNull', 'argv' => [$params[0], $boolean]];
        } elseif ($params[1] === 'in') {
            $where = [
                ['fun' => 'where', 'argv' => [$params[0], '>=', $params[2][0]]],
                ['fun' => 'where', 'argv' => [$params[0], '<=', $params[2][1]]]
            ];
        } elseif (is_array($params[2])) {
            $where = ['fun' => $params[1] === '=' ? 'whereIn' : 'whereNotIn', 'argv' => [$params[0], $params[2], $boolean]];
        } else {
            $where = ['fun' => 'where', 'argv' => [$params[0], $params[1], $params[2], $boolean]];
        }
        return $where;
    }

    private function makRaw($field, $rename = true)
    {
        if ($rename && $field['_value'] !== '*' && $field['_value'] !== $field['rename']) {
            return \DB::raw($field['table'] . '.' . $field['_value'] . ' as ' . $field['rename']);
        }
        return $field['table'] . '.' . $field['_value'];
    }

    /*********************************************************/

    /**
     * @param $filed
     * @param $root OperationNode
     * @param array $renames
     * @return array | OperationNode
     * @throws \Exception
     */
    private function parseToMultiTree($filed, $root = null, $renames = [])
    {
        if (!preg_match('/\\||:/', $filed['_value'])) {
            return [$filed['rename'], $filed['table'] . '.' . $filed['_value']];
        }
        if (in_array($filed['rename'], $renames)) {
            throw new \Exception("relationship \"{$filed['rename']}\" call error");
        }
        array_push($renames, $filed['rename']);
        $fun = explode('|', $filed['_value']);
        $len = count($fun) - 1;
        $currentNode = $childNode = null;
        for ($i = $len; $i >= 0; $i--) {
            $item = explode(':', $fun[$i]);
            if (!in_array($item[0], $this->fun)) {
                throw new \Exception(" \"{$filed['rename']}\" function \"{$item[0]}\" not exist ");
            }
            $currentNode = new OperationNode($item[0], $filed['rename']);
            if ($childNode) {
                $childNode->parentNode = $currentNode;
            }
            $params = isset($item[1]) ? $this->parseOperationParams($item[1], $renames, $currentNode, $childNode) : [$childNode];
            $currentNode->createMultiTree(...$params);
            $childNode = $currentNode;
        }
        $currentNode->parentNode = $root;
        return $currentNode;
    }

    /**
     * @param $params
     * @param $renames
     * @param $currentNode
     * @param $childNode
     * @return array
     * @throws \Exception
     */
    private function parseOperationParams($params, $renames, $currentNode, $childNode)
    {
        $params = explode(',', $params);
        foreach ($params as &$value) {
            if ($value{0} === '@') {
                $field = $this->find(substr($value, 1));
                $value = $this->parseToMultiTree($field, $currentNode, $renames);
            } elseif ($value === '$') {
                $value = $childNode;
            } elseif (!is_numeric($value)) {
                if (preg_match_all('/{.+?}/', $value, $items)) {
                    foreach ($items[0] as $item) {
                        $field = $this->find(substr($item, 1, -1));
                        $field = $field['table'] . '.' . $field['_value'];
                        $value = preg_replace('/{.+?}/', $field, $value, 1);
                    }
                }
//                else {
//                    $field = $this->find($value);
//                    $value = $field['table'] . '.' . $field['_value'];
//                }
            }
        }
        return $params;
    }

    /**
     * @param $node OperationNode
     * @param $root
     */
    private function cutMultiTree($node, $root = null)
    {
        $root = $root ?: $node;
        if ($node->floor < 2) {
            if ($node->type === 2 && $node->floor === 1) {
                $this->loop[1][$node->belongTo] = $node;
                foreach ($node->values as &$value) {
                    if ($value instanceof OperationNode) {
                        $this->loop[0][$value->belongTo] = $value;
                        $value = '@' . $value->belongTo;
                    }
                }
            } else {
                $this->loop[0][$node->belongTo] = $node;
            }
        } else {
            foreach ($node->values as &$value) {
                if ($value instanceof OperationNode) {
                    if ($node->hasJump) {
                        $this->loop[$node->floor - 1][$value->belongTo] = $root;
                        $this->cutMultiTree($value, $value);
                        $value = '@' . $value->belongTo;
                    } else {
                        $this->cutMultiTree($value, $root);
                    }
                } elseif (is_array($value)) {
                    $this->loop[0][$value[0]] = $value[1];
                    $value = '@' . $value[0];
                }
            }
        }
    }

    /*********************************************************/

    private function setJoins($joins)
    {
        for ($i = 0; $i < count($joins); $i++) {
            for ($j = count($joins) - 1; $j > $i; $j--) {
                if ($joins[$i][1] === $joins[$j][2]) {
                    array_splice($joins, $i, $j - $i + 1, [
                        [$joins[$j][0], $joins[$j][1], $joins[$i][2]]
                    ]);
                }
            }
            $index = count($this->joins);
            foreach ($this->joins as $k => $join) {
                if ($join[0] === $joins[$i][0]) {
                    $index = $k;
                }
            }
            $this->joins[$index] = $joins[$i];
        }
    }

    /**
     * @param $field
     * @param $relation RelationNode
     * @return mixed
     */
    private function findByRelations($field, $relation)
    {

        $argv = explode('.', $field);
        $joins = $relation->join;
        $value = array_pop($argv);

        foreach ($argv as $join) {
            if (!isset($relation->childNodes[$join])) {
                throw new \Exception(" \"{$field}\" relation \"{$join}\" not exist");
            }
            $relation = $relation->childNodes[$join];
            $joins = array_merge($joins, $relation->join);
        }

        if ($argv = $this->findInJoins($value, $joins, $relation)) {
            $this->setJoins($joins);
            return ['model' => $relation, 'field' => $argv[1], 'table' => $argv[0]];
        }

        if (isset($relation->fields[$value])) {
            $this->setJoins($joins);
            if ($value === '*') {
                return $this->addKey($this->makeField($field, null, '*'), 'table', $relation->name);
            }
            return ['model' => $relation, 'field' => $value];
        } else {
            throw new \Exception("field \"{$field}\" value \"{$value}\" not exist");
        }
    }

    /**
     * @param $field
     * @param $relation RelationNode
     * @return mixed|null
     */
    private function findInRelations($field, $relation)
    {
        $relations = [[$relation, []]];
        while (count($relations)) {
            $children = array();
            foreach ($relations as $item) {
                $joins = array_merge($item[1], $item[0]->join);

                if ($argv = $this->findInJoins($field, $joins, $item[0])) {
                    $this->setJoins($item[1]);
                    return ['model' => $item[0], 'field' => $argv[1], 'table' => $argv[0]];
                }
                if (isset($item[0]->fields[$field])) {
                    $this->setJoins($joins);
                    return ['model' => $item[0], 'field' => $field];
                }

                foreach ($item[0]->childNodes as $value) {
                    array_push($children, [$value, $joins]);
                }
            }
            $relations = $children;
        }
        return null;
    }

    private function findInJoins($field, &$joins, &$relation)
    {
        foreach ($relation->join as $item) {
            if (explode('.', $item[1])[1] === $field) {
                $argv = explode('.', $item[2]);

                array_pop($joins);
                $relation = $relation->parentNode;

                return $this->findInJoins($argv[1], $joins, $relation) ?: $argv;
            }
        }
        return null;
    }

    private function addKey($array, $key, $value)
    {
        $array[$key] = $value;
        return $array;
    }

    private function makeRelations($model, $range)
    {
        $tables = array();
        $models = ['' => [$model, [], &$this->relations]];
        while (count($models)) {
            $children = array();
            foreach ($models as $key => $value) {
                $table = $value[0]->getTable();
                if (in_array($table, $tables)) continue;

                $node = new RelationNode($value[0], $value[1]);
                $node->fields = $this->getFieldsByModel($value[0], $node->path, $node->foreignKey);

                $value[2] ? $value[2]->addChild($key, $node) : $value[2] = $node;
                foreach ($this->getJoinsByModel($value[0]) as $k => $join) {
                    $children[$k] = [end($join)[0], $join, $node];
                }
                array_push($tables, $table);
            }
            if (--$range < 0) {
                break;
            }
            $models = $children;
        }
    }

    /**
     * @param $model \Illuminate\Database\Eloquent\Model;
     * @param $path string
     * @param $fk string
     * @return array
     */
    private function getFieldsByModel($model, $path = '', $fk = null)
    {
        $fills = $model->getFillable();
        $primaryKey = $model->getKeyName();

        if (!in_array($primaryKey, $fills)) $fills[] = $primaryKey;
        $casts[$primaryKey] = 'only';
        if ($model->timestamps) {
            $casts['created_at'] = $casts['updated_at'] = 'date';
            if (!in_array('created_at', $fills)) $fills[] = 'created_at';
            if (!in_array('updated_at', $fills)) $fills[] = 'updated_at';
        }
        $fills = array_diff($fills, [$fk]);
        $casts = array_merge($casts, $model->getCasts(), $model->fieldTypes ?: []);

        $fields = array();
        foreach ($fills as $field) {
            $fields[$field] = $this->makeField($path ? "{$path}.{$field}" : $field,
                isset($casts[$field]) ? $casts[$field] : 'numeric', $field);
        }

        foreach ($this->customFields as $key => $value) {
            if ($path && strpos($key, $path) === 0) {
                $args = explode('.', $key);
                $field = end($args);
                $fields[$field] = $this->makeField($key, 'numeric', $value, true);
            } elseif (!$path && !strpos($key, '.')) {
                $fields[$key] = $this->makeField($key, 'numeric', $value, true);
            }
        }
        return $fields;
    }

    private function makeField($name, $type, $value, $custom = false)
    {
        return [
            'name' => $name,
            '_value' => $value,
            'itype' => $type,
            'custom' => $custom,
        ];
    }

    /**
     * @param $model \Illuminate\Database\Eloquent\Model;
     * @return array [Model | string, string, string]
     */
    private function getJoinsByModel($model)
    {
        $joins = $model->joins ?: [];
        if (count($this->customJoins)) {
            $joins = array_filter($joins, function ($item) {
                return in_array($item, $this->customJoins);
            });
        }
        $relations = [];
        foreach ($joins as $key => $value) {
            $fn_name = is_int($key) ? $value : $key;
            $relation = $model->$fn_name();
            if ($relation instanceof BelongsTo) {
                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedOtherKeyName(), $relation->getQualifiedForeignKey()]];
                } else {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKey()]];
                }

            } elseif ($relation instanceof HasOne || $relation instanceof HasMany) {

                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getForeignKey(), $relation->getQualifiedParentKeyName()]];
                } else {
                    $relations[$fn_name] = [[$relation->getRelated(), $relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName()]];
                }

            } elseif ($relation instanceof BelongsToMany) {

                if (app()->version() < '5.6') {
                    $relations[$fn_name] = [[$relation->getTable(), $relation->getForeignKey(), $relation->getQualifiedParentKeyName()],
                        [$relation->getRelated(), $relation->getRelated()->getQualifiedKeyName(), $relation->getOtherKey()]];
                } else {
                    $relations[$fn_name] = [[$relation->getTable(), $relation->getForeignPivotKeyName(), $relation->getQualifiedParentKeyName()],
                        [$relation->getRelated(), $relation->getRelated()->getQualifiedKeyName(), $relation->getQualifiedRelatedPivotKeyName()]];
                }

            } elseif ($relation instanceof HasManyThrough) {

                if (app()->version() < '5.6') {
                    $localKey = is_int($key) ? $relation->getHasCompareKey() : $model->getTable() . '.' . $value;
                    $relations[$fn_name] = [[$relation->getParent(), $localKey, $relation->getThroughKey()],
                        [$relation->getRelated(), $relation->getQualifiedParentKeyName(), $relation->getForeignKey()]];
                } else {
//                    $localKey = is_int($key) ? $relation->getHasCompareKey() : $model->getTable() . '.' . $value;
                    $relations[$fn_name] = [[$relation->getParent(), $relation->getQualifiedFirstKeyName(), $relation->getQualifiedLocalKeyName()],
                        [$relation->getRelated(), $relation->getQualifiedParentKeyName(), $relation->getQualifiedForeignKeyName()]];
                }
            }
        }
        return $relations;
    }
}
