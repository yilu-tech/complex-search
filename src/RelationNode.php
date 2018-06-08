<?php

namespace Yilu\ComplexSearch;

class RelationNode
{
    public $name;

    public $path;

    public $primaryKey;

    public $foreignKey;

    public $join = array();

    public $fields = array();

    public $parentNode = null;

    public $childNodes = array();

    public function __construct($model, $join)
    {
        $this->name = $model->getTable();
        $this->primaryKey = $model->getKeyName();
        $this->parseJoin($join);
    }

    public function addChild($key, $node)
    {
        $node->parentNode = $this;
        $node->path = $this->path ? $this->path . '.' . $key : $key;
        $this->childNodes[$key] = $node;
    }

    public function findNode($name)
    {
        foreach ($this->childNodes as $child) {
            if ($child->name === $name) {
                return $child;
            } elseif ($node = $child->findNode($name)) {
                return $node;
            }
        }
        return null;
    }

    private function parseJoin($join)
    {
        $len = count($join);
        if ($len) {
            $this->foreignKey = explode('.', $join[0][1])[1];

            $join[$len - 1][0] = $join[$len - 1][0]->getTable();
            $this->join = $join;
        } else {
            $this->foreignKey = null;
        }
    }
}