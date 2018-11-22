<?php

namespace YiluTech\ComplexSearch;

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

    protected $display = 'default';

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

    private $fun = ['add', 'mul', 'sub', 'div', 'sum', 'max', 'min', 'count', 'avg', 'if', 'date_format', 'round', 'cast', 'concat', 'abs'];

    public function make($request)
    {
        $this->request = $request;

        $this->action = $this->input('action');

        if ($this->root) {
            if (is_string($this->root)) {
                $this->root = new $this->root;
            }
            $this->makeRelation($this->root, $this->range);
        }

        if ($this->action !== 'fields') return;

        if (method_exists($this, 'addOptions')) {
            $this->addOptions();
        }

        if (!$this->root) return;

        $this->bindCondition();
    }

    public function get()
    {
        if ($this->action === 'fields') {

            return $this->root && $this->display !== 'simple' ? [
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

        } elseif ($this->action === 'export') {
            $data['params'] = $this->input('params', []);
            $data['fields'] = $this->input('fields', []);
            $data['extras'] = $this->input('extras', []);
            $data['controller'] = \Route::current()->getAction()['controller'];
            if ($this->input('groupBy')) {
                $data['groupBy'] = $this->input('groupBy');
            }
            if ($this->input('orderBy')) {
                $data['orderBy'] = $this->input('orderBy');
            }
            return urlencode(encrypt(json_encode($data)));
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
     * @param $node RelationNode
     * @return array
     */
    public function getFields($node = null)
    {
        $node = $node ?: $this->relations;
        $fields = array();
        foreach ($node->fields as $key => $value) {
            if ($this->validateField($value['name'], $node)) {
                if (empty($value['label'])) {
                    $value['label'] = trans($this->lang . '.' . $node->table . '.' . $key);
                }
                unset($value['_value']);
                unset($value['custom']);
                $fields[] = $value;
            }
        }
        foreach ($node->childNodes as $key => $value) {
            $fields[] = [
                'label' => trans($this->lang . '.' . $node->table . '.' . $key),
                'name' => $key . '.*',
                'children' => $this->getFields($value)
            ];
        }
        return $fields;
    }

    private function validateField($field, $node)
    {

//        if ($node->primaryKey === $field) {
//            return false;
//        }
        $field = $node->joinName . '.' . $field;
        foreach ($this->filterPreg as $value) {
            if (preg_match("/{$value}/", $field)) {
                return false;
            }
        }
        return true;
    }

    public function field($field, $type = 0)
    {
        $field = $this->find($field, $type);

        if ($field['custom']) {
            $field['_value'] = $this->parseToMultiTree($field)->toString();
        }

        return $this->makRaw($field, false);
    }

    public function find($str, $type = 0)
    {
        $field = $this->parseField($str);
        $nodes = [$this->relations];
        while (count($nodes)) {
            $node = array_shift($nodes);
            if ($this->machField($field, $node, $mach, $type)) {
                if ($mach) {
                    $field['table'] = $mach->parentNode->table;
                    $field['name'] = $mach->otherKey;
                    $field['node'] = $mach->parentNode;
                    if (!$field['rename']) $field['rename'] = $field['name'];
                } else {
                    $field['table'] = $node->table;
                    $field['node'] = $node;
                }
                break;
            }
            foreach ($node->childNodes as $node) array_push($nodes, $node);
        }
        if (!isset($field['node'])) {
            throw new \Exception("field \"$str\" not exist");
        }
        $field = array_merge($field, $field['node']->fields[$field['name']]);
        $this->setJoins($field['node']->joins())->setGroupBy($field['node']);
        return $field;
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

    public function hasCondition($field, $code = 0)
    {
        $conditions = $this->input('params', []);
        $bool = [0, 0];
        foreach ($conditions as $condition) {
            if (is_array($condition[0])) {
                foreach ($condition as $key => $item) {
                    if ($code <= 0 && strpos($item[0], $field) === strlen($item[0]) - strlen($field)) $bool[1] = -1;
                }
            } else {
                if ($code >= 0 && strpos($condition[0], $field) === strlen($condition[0]) - strlen($field)) $bool[0] = 1;
            }
            if (($bool[0] * $code > 0) || ($bool[1] * $code > 0) || ($bool[0] && $bool[1])) return true;
        }
        return false;
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
                if (isset($this->conditions[$condition[0]]) && !empty($this->conditions[$condition[0]]['custom'])) {
                    continue;
                }
                $condition = $this->formatWhere($condition);
            }
        }

        return $conditions;
    }

    protected function addWhere($query, $conditions)
    {
        foreach ($conditions as $condition) {
            if (isset($condition['fun'])) {
                if (isset($this->whereDef[$condition['name']])) {
                    $this->whereDef[$condition['name']]($query, $condition);
                } else {
                    $query->{$condition['fun']}(...$condition['argv']);
                }
            } elseif (is_array($condition[0])) {
                $query->where(function ($query) use ($condition) {
                    $this->addWhere($query, $condition);
                });
            } else {
                if (isset($this->whereDef[$condition[0]])) {
                    $this->whereDef[$condition[0]]($query, $condition);
                } else {
                    $query->where(...$condition);
                }
            }
        }
        return $this;
    }

    public function getQueryFields()
    {
        $fields = $this->input('fields', []);
        if ($orderBy = $this->getOrderBy()) {
            $fields[] = $orderBy['field'];
        }
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
        if (!$value) return $value;
        if (is_string($value)) {
            $value = [$value];
        }
        return array_map(function ($field) {
            return $this->field($field);
        }, $value);
    }

    protected function addGroupBy($query, $groups)
    {
        $query->groupBy(...$groups);

        return $this;
    }

    /**
     * @param $node RelationNode
     */
    private function setGroupBy($node)
    {
        if (!count($this->groupDef)) return;
        $groups = array();
        foreach ($this->groupDef as $name => $value) {
            if (in_array(is_int($name) ? $value : $name, $node->path())) {
                $groups[] = is_int($name) ? implode('.', array_merge($node->path(), [$node->otherKey])) : $value;
            }
        }
        $this->groupBy = array_unique(array_merge($this->groupBy, $groups));
        return $this;
    }

    public function getOrderBy($default = null)
    {
        $value = $this->input('orderBy', $default);
        if (!$value) {
            return $value;
        }

        $argv = explode(' ', $value);

        return [
            'field' => $argv[0],
            'direction' => isset($argv[1]) ? $argv[1] : 'asc'
        ];
    }

    protected function addOrderBy($query, $orderBy)
    {
        $query->orderBy($orderBy['field'], $orderBy['direction']);

        return $this;
    }

    protected function addJoins()
    {
        foreach ($this->joins as $name => $join) {
            if (isset($this->joinDef[$name])) {
                $this->joinDef[$name]($this->query);
            } else {
                $this->query->leftJoin($join[0], $join[1], '=', $join[2]);
            }
        }
    }

    private function bindCondition()
    {
        foreach ($this->conditions as $key => $condition) {
            if (isset($condition['custom'])) continue;

            $field = $this->find($key);

            $field['node']->fields[$field['name']] = array_merge($field['node']->fields[$field['name']], $condition);
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
        $name = $params[0];
        $field = $this->find($name);
        if (!in_array($params[1], $this->sqlOperators[$field['itype']], true)) {
            throw new \Exception("where \"{$params[0]}\" operator \"{$params[1]}\" not exist");
        }

        if (is_string($params[2]) && strlen($params[2]) > 64) {
            throw new \Exception("\"{$name}\" value length more than 64");
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
            $where = ['name' => $name, 'fun' => 'whereNull', 'argv' => [$params[0], $boolean]];
        } elseif ($params[1] === '<>' && $params[2] === null) {
            $where = ['name' => $name, 'fun' => 'whereNotNull', 'argv' => [$params[0], $boolean]];
        } elseif ($params[1] === 'in') {
            $where = [
                ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], '>=', $params[2][0]]],
                ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], '<=', $params[2][1]]]
            ];
        } elseif (is_array($params[2])) {
            $where = ['name' => $name, 'fun' => $params[1] === '=' ? 'whereIn' : 'whereNotIn', 'argv' => [$params[0], $params[2], $boolean]];
        } else {
            $where = ['name' => $name, 'fun' => 'where', 'argv' => [$params[0], $params[1], $params[2], $boolean]];
        }
        return $where;
    }

    private function makRaw($field, $rename = true)
    {
        if (!$field['custom']) {
            $field['_value'] = $field['table'] . '.' . $field['_value'];
        }

        if ($rename && $field['_value'] !== '*' && $field['_value'] !== $field['rename']) {
            return \DB::raw($field['_value'] . ' as ' . $field['rename']);
        }

        return $field['_value'];
    }

    function parseField($str)
    {
        $argv = explode('|', $str);

        $path = explode('.', $argv[0]);

        $field['name'] = array_pop($path);

        $field['path'] = $path;

        $field['rename'] = isset($argv[1]) ? $argv[1] : $field['name'];

        return $field;
    }

    /**
     * @param $field
     * @param $node RelationNode
     * @param $mach RelationNode
     * @param $type int
     * @return bool
     */
    function machField($field, $node, &$mach = null, $type = 0)
    {
        if (!$node->inPath($field['path'])) return false;

        if ($type !== 1 && ($mach = $node->inJoinField($field['name']))) {
            return true;
        }

        return $field['name'] === '*' || $node->inField($field['name']);
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
        if (!$filed['custom']) {
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
        foreach ($joins as $name => $join) {
            if (isset($this->joins[$name])) continue;

            $this->joins[$name] = $join;
        }
        return $this;
    }

    private function addKey($array, $key, $value)
    {
        $array[$key] = $value;
        return $array;
    }

    public function makeRelation($model, $range)
    {
        $models = [[[$model, null, &$this->relations]]];
        $tables = array();
        while (count($models)) {
            $items = array_shift($models);
            $children = [];
            foreach ($items as $model) {
                if (in_array($model[0]->getTable(), $tables)) continue;

                $parent = $this->makeNode($model[0], $model[1]);
                $tables[] = $parent->table;
                $model[2] ? $model[2]->addChild($parent) : $model[2] = $parent;

                foreach ($this->getJoinsByModel($model[0]) as $name => $joins) {
                    foreach ($joins as $key => $join) {
                        if ($key < count($joins) - 1) {
                            if (in_array($join[0]->getTable(), $tables)) continue;
                            $node = $this->makeNode($join[0], [$join[2], $join[1], $join[0]->getTable()]);
                            $node->appendTo($parent);
                            $parent = $node;
                            $tables[] = $parent->table;
                        } else {
                            $children[] = [$join[0], [$join[2], $join[1], $name], $parent];
                        }
                    }
                }
            }
            if (--$range >= 0) $models[] = $children;
        }
    }

    private function makeNode($model, $join)
    {
        $node = new RelationNode($model);

        $this->setFields($model, $node);

        if ($join) $node->setJoin(...$join);

        return $node;
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

    /**
     * @param $model \Illuminate\Database\Eloquent\Model;
     * @param $node RelationNode
     * @return array
     */
    private function setFields($model, $node)
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

        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($model))) {
            $casts['deleted_at'] = 'date';
            if (!in_array('deleted_at', $fills)) $fills[] = 'deleted_at';
        }
        $casts = array_merge($casts, $model->getCasts(), $model->fieldTypes ?: []);

        foreach ($fills as $field) {
            $node->fields[$field] = $this->makeField($field, isset($casts[$field]) ? $casts[$field] : 'numeric', $field);
        }

        foreach ($this->customFields as $key => $value) {
            $field = $this->parseField($key);
            if ($node->inPath($field['path'])) {
                $node->fields[$field['name']] = $this->makeField($field['name'], 'numeric', $value, true);
            }
        }
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
