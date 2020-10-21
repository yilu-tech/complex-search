##说明文档
---

#### 属性

- **root**
  查询主表，若未定义需自定义查询对象
- **action**
  查询行为，包含 prepare，query，export
- **range**
  表关联深度, `INF`表示无限制
- **joins**
  定义可管理模型，关联关系在`Model`上定义`joins`属性说明，值是`Model`上定义的`relation`的方法名
- **hidden**
  隐含字段，查询时默认请求字段
- **orderBy**
  定义排序方式, 规则：字段名 + 空格 + 排序方式（asc可省略） + （分号 + 其它排序字段）
  例:`type;created_at desc`
- **filterPreg**
  定义过滤字段规则，被过滤的字段不会通过接口返回
  例：`$fllterPreg = ['user\\.id', '\\.deleted_at', 'user\\.detail\\.']`
- **exportLinkTime**
  导出链接时效
- **fieldDef**
  自定义查询字段 例：`$fieldDef['username'] = 'concat:prefix_,@name'`
- **joinDef**
  自定义关联关系 例：
```php
funtion query() {
    $this->joinDef['detail'] = funtion($join) {
        $join->on($this->field('user.id'), '=', $this->field('detal.user_id'))
             ->on($this->field('user.type'), '=', $this->field('detal.type'));
    }
    parent::query();
}
```
- **whereDef**
  自定义条件 例：
```php
funtion query() {
    $this->whereDef['username'] = funtion($query, $condition) {
    	// $condition => ['name' => '字段名', 'fun' => '条件方法名', 'argv' => ['字段', '比较符', '值', 'and|or']]
        $query->where('username', $condition['argv'][2] . '_suffix');
    }
    parent::query();
}
```
- **headers**
  定义表头 例：
```php
$headers = [
	[
    	'value'     => 'id|user_id', //字段id,重命名为user_id
        'label'     => '用户id',     //表头显示名字,未定义则从翻译信息中获取
        'custom'    => true,         //是否为自定义字段，自定义字段是不会加入查询，在返回结果时需要用户自己构造
        'color'     => 'red',        //显示字体宽度
        'width'     => 100,          //显示宽度
        'minWidth'  => 100,          //显示最小宽度
        'maxWidth'  => 100,          //显示最大宽度
        'decimal'   => 2,            //保留小数位数，格式化成浮点型
        'cascade'   => true,         //可获取多层下的数据
        'options'   => [             //可渲染显示数据列表
            'value'     => 1,        //配置值
            'label'     => '是',     //显示数据
            'color'     => 'red',    //显示字体颜色
            'operator'  => '|',      //比较符
        ],
        'hasMany'   => true,         //可匹配多条渲染数据
        'separator' => ','           //多条数据连接符,默认“，”
    ],
    'name',                          // 简写，只需定义value
]
```
- **conditions**
  定义表头 例：
```php
$conditions = [
	'name' => [                      //筛选字段
    	'ctype'     => 'keyword',    //条件类型
                                     // keyword:关键字匹配，可显示到搜索框内；
                                     // numeric:数字
                                     // time: 时间选择
                                     // data: 日期选择
        'value'     => 'defalut',    //条件默认值
        'label'     => '用户id',     //条件显示名称
        'operator'  => '=',          //比较符
        'range'     => [1, 2],       //取值范围，第0项为空表负无穷，第1项为空表正无穷
        'format'    => 'Y-m-d',      //定义数据格式
        'custom'    => true,         //是否为自定义条件，自定义条件需要在"whereDef"定义处理函数
        'isFullLabel' => false,      //名称不显示路径部分，只取最后一部分
    ],
    'type' => [                      //筛选字段
		'ctype'     => 'multiple',   //多选条件类型,radio:单选；multiple:可多选；tree-select：树形选择
        'options'   => [             // 可渲染显示数据列表
            'value'     => 1,        // 配置值
            'label'     => '是',     // 显示数据
        ],
    ],
   'money' => [                      //筛选字段
		'ctype'     => 'numeric-in', //区间条件类型,numeric-in,date-in,time-in
    ],
]
```

#### 方法

- **prepare**
  准备阶段，一般定义`condition`,`action`等于`prepare`时会调用该方法
- **query**
  查询阶段，定义查询规则和表关联关系,`action`等于`query`时会调用该方法
- **execQuery**
  执行查询，可重写来处理返回结果

#### 示例

```php

class MemberList extends ComplexSearch
{
    public $root = Member::class;

    protected $headers = [
        ['value' => 'id', 'label' => 'ID'],
        ['value' => 'name', 'label' => '名称'],
        ['value' => 'type', 'label' => '类型'],
        ['value' => 'gender', 'label' => '性别', 'options' => [
            ['value' => 1, 'label' => '男'],
            ['value' => 2, 'label' => '女'],
        ]],
        ['value' => 'detail.avatar', 'label' => '头像'],
        ['value' => 'created_at', 'label' => '创建时间'],
    ];

    protected $joins = ['detail'];

    protected $hidden = [];

    protected $conditions = [
        'name_or_mobile' => [
            'ctype' => 'keyword',
            'custom' => true,
        ],
        'type' => [
            'ctype' => 'radio',
        ],
        'gender' => [
            'ctype' => 'radio',
        ],
        'created_at' => [
            'ctype' => 'date',
            'format' => 'Y-m-d'
        ],
    ];

    public function prepare()
    {
        $this->conditions['type']['options'] = MemberType::select('id as value', 'name as lebel')->get();
    }

    public function query()
    {
        $this->whereDef['name_or_mobile'] = function ($query, $condition) {
            $query->where(function ($query) use ($condition) {
                $query->where($this->field('name'), $condition['argv'][2])
                     ->orWhere($this->field('mobile'), $condition['argv'][2]);
            });
        };
        $this->joinDef['detail'] = function ($join) {
            $join->on($this->field('id'), $this->field('detail.member_id'))
                ->on($this->field('type'), $this->field('detail.type'));
        };
        return tap(parent::query(), function ($query) {
            $query->where($this->field('type'), 1);
        });
    }

    public function execQuery()
    {
        return tap(parent::execQuery(), function ($result) {
            foreach ($result as $item) {
                $item->name = 'prefix_' . $item->name;
            }
        });
    }
}

```
