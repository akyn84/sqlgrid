<?php

namespace Masala;

use Nette\Application\IPresenter,
    Nette\Caching\IStorage,
    Nette\Caching\Cache,
    Nette\Database\Context,
    Nette\Database\Table\ActiveRow,
    Nette\Database\Table\Selection,
    Nette\InvalidStateException,
    Nette\Localization\ITranslator,
    Nette\Utils\Validators;

/** @author Lubomir Andrisek */
final class Builder implements IBuilder {

    /** @var array */
    private $actions = [];

    /** @var IAdd */
    private $add;

    /** @var array */
    private $annotations;

    /** @var array */
    private $arguments = [];

    /** @var IBuild */
    private $build;

    /** @var IButton */
    private $button;

    /** @var Cache */
    private $cache;

    /** @var array */
    private $config;

    /** @var string */
    private $control;

    /** @var array */
    private $columns = [];

    /** @var Context */
    private $database;

    /** @var array */
    private $dialogs = [];

    /** @var array */
    private $defaults;

    /** @var IEdit */
    private $edit;

    /** @var IProcess */
    private $export;

    /** @var IProcess */
    private $exportService;

    /** @var IFetch */
    private $fetch;

    /** @var IFilter */
    private $filter;

    /** @var IChart */
    private $chart;

    /** @var int */
    private $group;

    /** @var array */
    private $groups = [];

    /** @var string */
    private $having;

    /** @var IProcess */
    private $import;

    /** @var array */
    private $innerJoin = [];

    /** @var array */
    private $join = [];

    /** @var array */
    private $leftJoin = [];

    /** @var int */
    private $limit;

    /** @var IListener */
    private $listener;

    /** @var array */
    private $keys = [];

    /** @var int */
    private $offset;

    /** @var array */
    private $order;

    /** @var array */
    private $post;

    /** @var IPresenter */
    private $presenter;

    /** @var array */
    private $primary = [];

    /** @var IRemove */
    private $remove;

    /** @var IRowFormFactory */
    private $row;

    /** @var IProcess */
    private $service;

    /** @var string */
    private $sort;

    /** @var string */
    private $select;

    /** @var IStorage */
    private $storage;

    /** @var string */
    private $sum;

    /** @var array */
    private $where = [];

    /** @var string */
    private $table = '';

    /** @var IUpdate */
    private $update;

    /** @var ITranslator */
    private $translatorModel;

    /** @var string */
    private $query;

    public function __construct(array $config, ExportService $exportService, Context $database, IStorage $storage, IRowFormFactory $row, ITranslator $translatorModel) {
        $this->config = $config;
        $this->exportService = $exportService;
        $this->database = $database;
        $this->cache = new Cache($storage);
        $this->row = $row;
        $this->storage = $storage;
        $this->translatorModel = $translatorModel;
    }

    /** @return array */
    public function add() {
        $data = $this->getRow();
        if($this->add instanceof IAdd) {
            return $this->add->insert($data);
        }
        return $this->database->table($this->table)->insert($data)->toArray();
    }

    /** @return void */
    public function attached(Masala $masala) {
        $this->presenter = $masala->getPresenter();
        $this->control = $masala->getName();
        /** import */
        if ($this->import instanceof IProcess and ( $setting = $this->getSetting('import')) instanceof ActiveRow) {
            $this->import->setSetting($setting);
        } elseif ($this->import instanceof IProcess) {
            throw new InvalidStateException('Missing definition of import setting in table ' . $this->config['feeds'] . ' in call ' .
                $this->presenter->getName() . ':' . $this->presenter->getAction());
        }
        /** export */
        if ($this->export instanceof IProcess and false != $setting = $this->getSetting('export')) {
            $this->export->setSetting($setting);
        }
        /** process */
        if (false != $setting = $this->getSetting('process')) {
            $this->service->setSetting($setting);
        }
        $this->setKeys();
        /** select */
        foreach ($this->columns as $column => $annotation) {
            if (preg_match('/\sAS\s/', $annotation)) {
                throw new InvalidStateException('Use intented alias as key in column ' . $column . '.');
            } elseif (in_array($column, ['style', 'groups'])) {
                throw new InvalidStateException('Style and groups keywords are reserved for callbacks and column for Grid.jsx:update method. See https://github.com/landrisek/Masala/wiki/Select-statement. Use different alias.');
            }
            $this->inject($annotation, $column);
        }
        foreach ($this->getDrivers($this->table) as $driver) {
            if (!isset($this->columns[$driver['name']])) {
                $driver['nullable'] ? null : $driver['vendor']['Comment'] .= '@required';
                $this->inject($driver['vendor']['Comment'] . '@' . $driver['vendor']['Type'] . '@' . strtolower($driver['vendor']['Key']) . '@' . strtolower($driver['nativetype']), $driver['name']);
                isset($this->defaults[$driver['name']]) ? $this->columns[$driver['name']] = $this->table . '.' . $driver['name'] : null;
            }
        }
        if(isset($this->config['settings']) &&
            $this->presenter->getUser()->isLoggedIn() &&
            is_object($setting = json_decode($this->presenter->getUser()->getIdentity()->getData()[$this->config['settings']]))) {
            foreach($setting as $source => $annotations) {
                if($this->presenter->getName() . ':' . $this->presenter->getAction() == $source) {
                    foreach($annotations as $annotationId => $annotation) {
                        if(empty($annotation) && isset($this->annotations[$annotationId]['unrender'])) {
                            $this->annotations[$annotationId]['filter'] = false;
                            unset($this->annotations[$annotationId]['unrender']);
                        } else if(!preg_match('/' . $annotation . '/', $this->columns[$annotationId])) {
                            $this->inject($this->columns[$annotationId] . $annotation, $annotationId);
                        }
                    }
                }
            }
        }
        $select = 'SELECT ';
        $primary = $this->keys;
        foreach ($this->columns as $alias => $column) {
            if(empty($column)) {
                $column = 'NULL';
            } else if (!preg_match('/\.|\s| |\(|\)/', trim($column))) {
                $column = $this->table . '.' . $column;
            }
            if(isset($primary[$column])) {
                unset($primary[$column]);
            }
            if($this->sanitize($column)) {
                $select .= ' ' . $column . ' AS `' . $alias . '`, ';
            }
        }
        foreach($primary as $column => $alias) {
            if(isset($this->columns[$alias]) and !preg_match('/\./', $this->columns[$alias])) {
                throw new InvalidStateException('Alias ' . $alias . ' is reserved for primary key.');
            }
            $select .= ' ' . $column . ' AS `' . $alias . '`, ';
        }
        $this->query = rtrim($select, ', ');
        $this->sum = 'SELECT COUNT(*) AS sum ';
        $this->select = rtrim(ltrim($select, 'SELECT '), ', ');
        $from = ' FROM ' . $this->table . ' ';
        foreach ($this->join as $join) {
            $from .= ' JOIN ' . $join . ' ';
        }
        foreach ($this->leftJoin as $join) {
            $from .= ' LEFT JOIN ' . $join . ' ';
        }
        foreach ($this->innerJoin as $join) {
            $from .= ' INNER JOIN ' . $join . ' ';
        }
        $this->query .= $from;
        $this->sum .= $from;
    }

    /** @return IBuilder */
    public function build(IBuild $build) {
        $this->build = $build;
        return $this;
    }

    /** @return IBuilder */
    public function button(IButton $button) {
        $this->button = $button;
        return $this;
    }

    /** @return IBuilder */
    public function copy() {
        return new Builder($this->config, $this->exportService, $this->database, $this->storage, $this->row, $this->translatorModel);
    }
    
    private function column($column) {
        if (true == $this->getAnnotation($column, 'hidden')) {
        } elseif (true == $this->getAnnotation($column, ['addSelect', 'addMultiSelect'])) {
            $this->defaults[$column] = $this->getList($column);
        } elseif (is_array($enum = $this->getAnnotation($column, 'enum')) and false == $this->getAnnotation($column, 'unfilter')) {
            $this->defaults[$column] = $enum;
        } else {
            $this->defaults[$column] = '';
        }
        return $this;
    }

    /** @return IBuilder */
    public function dialogs(array $dialogs) {
        $this->dialogs = $dialogs;
        return $this;
    }

    /** @return array */
    public function delete() {
        $data = $this->getRow();
        if(empty($this->primary)) {
            throw new InvalidStateException('Primary keys were not set.');
        }
        if($this->remove instanceof IRemove) {
            $this->remove->remove($this->primary, $data);
        } else {
            $resource = $this->database->table($this->table);
            foreach($this->primary as $column => $value) {
                $resource->where($column, $value);
            }
            $resource->delete();
        }
        return ['remove' => true];
    }

    /** @return IBuilder */
    public function export($export) {
        $this->export = ($export instanceof IProcess) ? $export : $this->exportService;
        return $this;
    }

    /** @return IBuilder */
    public function edit($edit) {
        if(true == $edit) {
            $this->edit = new Edit();
        } else {
            $this->edit = $edit;
        }
        $this->actions['add'] = 'add';
        $this->actions['edit'] = 'edit';
        return $this;
    }

    /** @return IBuilder */
    public function fetch(IFetch $fetch) {
        $this->fetch = $fetch;
        return $this;
    }

    /** @return IBuilder */
    public function filter(IFilter $filter) {
        $this->filter = $filter;
        return $this;
    }

    /** @return bool */
    public function getAnnotation($column, $annotation) {
        if (is_array($annotation)) {
            foreach ($annotation as $annotationId) {
                if (isset($this->annotations[$column][$annotationId])) {
                    return true;
                }
            }
            return false;
        } elseif (isset($this->annotations[$column][$annotation]) and is_array($this->annotations[$column][$annotation])) {
            return $this->annotations[$column][$annotation];
        } elseif (isset($this->annotations[$column][$annotation])) {
            return true;
        } else {
            return false;
        }
    }

    /** @return array */
    public function getActions() {
        return $this->actions;
    }

    /** @return array */
    public function getArguments() {
        return $this->arguments;
    }

    /** @return IButton */
    public function getButton() {
        return $this->button;
    }

    /** @return string | Bool */
    public function getColumn($key) {
        if (isset($this->columns[$key])) {
            return $this->columns[$key];
        }
        return false;
    }

    /** @return array */
    public function getColumns() {
        return $this->columns;
    }

    /** @return array */
    public function getConfig($key) {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }
        return [];
    }

    /** @return array */
    public function getDefaults() {
        return $this->defaults;
    }

    /** @retun array */
    public function getDialogs() {
        if($this->edit instanceof IEdit || true == $this->edit) {
            $this->dialogs['add'] = 'add';
            $this->dialogs['edit'] = 'edit';
        }
        return $this->dialogs;
    }

    /** @return array */
    public function getDrivers($table) {
        $driverId = $this->getKey('attached', $table);
        if (null == $drivers = $this->cache->load($driverId)) {
            foreach($this->database->getConnection()->getSupplementalDriver()->getColumns($table) as $driver) {
                $drivers[$driver['name']] = $driver;
            }
            $this->cache->save($driverId, $drivers);
        }
        return $drivers;
    }

    /** @return IEdit */
    public function getEdit() {
        return $this->edit;
    }

    /** @return IProcess */
    public function getExcel() {
        return $this->export;
    }

    /** @return IProcess */
    public function getExport() {
        return $this->export;
    }

    /** @return string */
    public function getFilter($key) {
        if (isset($this->where[trim($key)])) {
            return preg_replace('/\%/', '', $this->where[$key]);
        }
        return '';
    }

    /** @return array */
    public function getFilters() {
        return $this->where;
    }

    /** @return string */
    public function getFormat($table, $column) {
        $drivers = $this->getDrivers($table);
        if(!isset($drivers[$column])) {
            $select = 'NULL';
        } else if('DATE' == $drivers[$column]['nativetype']) {
            $select = 'DISTINCT(DATE_FORMAT(' . $column . ', ' . $this->config['format']['date']['select'] . '))';
        } else if('TIMESTAMP' == $drivers[$column]['nativetype']) {
            $select = 'DISTINCT(DATE_FORMAT(' . $column . ', ' . $this->config['format']['date']['select'] . '))';
        } else {
            $select = $column;
        }
        return $select . ' AS ' . $column;

    }

    /** @return IChart */
    public function getChart() {
        return $this->chart;
    }

    /** @return array */
    public function getGroup() {
        return $this->groups;
    }

    /** @return string */
    public function getId($status) {
        return md5($this->control . ':' . $this->presenter->getName() . ':' . $this->presenter->getAction()  . ':' . $status . ':' . $this->presenter->getUser()->getId());
    }

    /** @return IProcess */
    public function getImport() {
        return $this->import;
    }

    /** @return string */
    private function getKey($method, $parameters) {
        return str_replace('\\', ':', get_class($this)) . ':' . $method . ':' . $parameters;
    }

    /** @return IListener */
    public function getListener() {
        return $this->listener;
    }

    /** @return array */
    public function getList($alias) {
        if(!preg_match('/\(/', $this->columns[$alias]) && preg_match('/\./', $this->columns[$alias])) {
            $table = trim(preg_replace('/\.(.*)/', '', $this->columns[$alias]));
            $column = trim(preg_replace('/(.*)\./', '', $this->columns[$alias]));
        } else {
            $table = $this->table;
            $column = $alias;
        }
        $key = $this->getKey('getList', $this->columns[$alias]);
        $list = $this->cache->load($key);
        if(isset($this->where[$table . '.' . $column]) && is_array($this->where[$table . '.' . $column])) {
            return array_combine($this->where[$table . '.' . $column], $this->where[$table . '.' . $column]);
        } else if(isset($this->where[$column]) && is_array($this->where[$column])) {
            return array_combine($this->where[$column], $this->where[$column]);
        } else if($this->filter instanceof IFilter && !empty($list = $this->filter->getList($alias))) {
        } else if (null == $list && isset($this->getDrivers($table)[$column])) {
            if(true == $this->sanitize($this->columns[$alias]) || is_array($primary = $this->database->table($table)->getPrimary())) {
                $select = $this->getFormat($table, $column);
                $primary = $column;
            } else {
                $select = $this->getFormat($table, $column) . ', ' . $primary;
            }
            $list = $this->database->table($table)
                ->select($select)
                ->where($column . ' IS NOT NULL')
                ->where($column . ' !=', '')
                ->group($column)
                ->order($column)
                ->fetchPairs($primary, $column);
            $this->cache->save($key, $list);
        } else if(null == $list) {
            $list = [];
        }
        return $list;
    }

    /** @return array */
    public function getOffset($offset) {
        if (empty($this->join) and empty($this->leftJoin) and empty($this->innerJoin)) {
            $row = $this->getResource()
                ->order($this->sort)
                ->limit(1, $offset)
                ->fetch();
        } else {
            $limit = count($this->arguments);
            $arguments = $this->arguments;
            $arguments[$limit] = 1;
            $arguments[$limit + 1] = intval($offset);
            $arguments = array_values($arguments);
            $row = $this->database->query($this->query . ' LIMIT ? OFFSET ? ', ...$arguments)->fetch();
        }
        if($row instanceof ActiveRow) {
            return $row->toArray();
        } elseif(is_object($row)) {
            return (array) $row;
        } else {
            return [];
        }
    }

    /** @return array */
    public function getOffsets() {
        if($this->fetch instanceof IFetch) {
            $data = $this->fetch->fetch($this);
        } else if(null == $data = $this->cache->load($hash = md5(strtolower(preg_replace('/\s+| +/', '', trim($this->query . $this->offset)))))) {
            $this->arguments[] = intval($this->limit);
            $this->arguments[] = intval($this->offset);
            if (empty($this->join) and empty($this->leftJoin) and empty($this->innerJoin)) {
                $resource = $this->getResource()
                    ->limit($this->limit, $this->offset)
                    ->order($this->sort)
                    ->fetchAll();
                $data = [];
                foreach($resource as $row) {
                    $data[] = $this->build instanceof IBuild ? $this->build->build($row->toArray()) : $row->toArray();
                }
            } else {
                $arguments = array_values($this->arguments);
                $resource = $this->database->query($this->query . ' LIMIT ? OFFSET ? ', ...$arguments);
                $data = [];
                foreach($resource as $row) {
                    $data[] = $this->build instanceof IBuild ? $this->build->build((array) $row) : (array) $row;
                }
            }
            /** if(!empty($data)) {
                $this->cache->save($this->control . ':' . $hash . ':' . $this->offset, $data, [Cache::EXPIRE => '+1 hour']);
             }*/
            $this->logQuery($hash);
        }
        return $data;
    }

    /** @return int */
    public function getPagination() {
        return $this->config['pagination'];
    }

    /** @return array */
    public function getPost($key) {
        if(empty($key) && empty($this->post)) {
            $this->post = json_decode(file_get_contents('php://input'), true);
            foreach($this->post as $column => $value) {
                if(is_string($value)) {
                    $this->post[$column] = ltrim($value, '_');
                }
            }
            return $this->post;
        } else if(empty($key)) {
            return $this->post;
        }
        if(isset($this->post[$key])) {
            return $this->post[$key];
        }
        $this->post = json_decode(file_get_contents('php://input'), true);
        if(!isset($this->post[$key])) {
            return [];
        }
        return $this->post[$key];
    }

    /** @return IRemove */
    public function getRemove() {
        return $this->remove;
    }

    /** @return Selection */
    public function getResource() {
        $dataSource = $this->database->table($this->table);
        (null == $this->select) ? null : $dataSource->select($this->select);
        foreach ($this->where as $column => $value) {
            is_numeric($column) ? $dataSource->where($value) : $dataSource->where($column, $value);
        }
        if(isset($this->groups[$this->group])) {
            foreach(explode(',', $this->groups[$this->group]) as $group) {
                $dataSource->where(trim($group) . ' IS NOT NULL');
            }
            $dataSource->group($this->groups[$this->group]);
        }
        empty($this->having) ? null : $dataSource->having($this->having);
        return $dataSource;
    }

    /** @return array */
    public function getRow() {
        foreach($row = $this->getPost('row') as $column => $value) {
            if(!isset($this->columns[$column]) || empty($this->columns[$column]) || strlen($column) > strlen(ltrim($column, '_'))) {
                unset($row[$column]);
            } else if(is_array($value) && isset($value['Label'])) {
                $row[$column] = $value['Label'];
            } else if (is_array($value) && isset($value['Attributes']) && $this->getAnnotation($column, ['int', 'tinyint'])) {
                $row[$column] = intval($value['Attributes']['value']);
            } else if (is_array($value) && isset($value['Attributes']) && $this->getAnnotation($column, ['decimal', 'float'])) {
                $row[$column] = floatval($value['Attributes']['value']);
            } else if (is_array($value) && isset($value['Attributes'])) {
                $row[$column] = $value['Attributes']['value'];
            } else if($this->getAnnotation($column, 'pri') && null == $value) {
                unset($row[$column]);
            } else if($this->getAnnotation($column, 'pri')) {
                $this->primary[$column] = $value;
                unset($row[$column]);
            } else if($this->getAnnotation($column, 'unedit')) {
                unset($row[$column]);
            } else if($this->getAnnotation($column, ['date', 'datetime', 'decimal', 'float', 'int', 'tinyint']) && empty(ltrim($value, '_'))) {
                unset($row[$column]);
            } else if (is_float($value) || $this->getAnnotation($column, ['decimal', 'float'])) {
                $row[$column] = floatval($value);
            } else if ((bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $row[$column] = date($this->config['format']['date']['query'], $converted);
            } else if(is_string($value)) {
                $row[$column] = ltrim($value, '_');
            }
        }
        return $row;
    }

    /** @return IProcess */
    public function getService() {
        return $this->service;
    }

    public function getSort() {
        return $this->sort;
    }

    /** @return array */
    public function getSpice() {
        $spices = (array) json_decode($this->presenter->request->getParameter(strtolower($this->control) . '-spice'));
        foreach($spices as $key => $spice) {
            if(is_array($spice)) {
                $allowed = $this->getList($key);
                foreach($spice as $id => $core) {
                    if(!isset($allowed[preg_replace('/(\_)*/', '', $core)])) {
                        unset($spices[$key][$id]);
                    }
                }
            }
        }
        return $spices;
    }

    /** @return int */
    public function getSum() {
        if($this->service instanceof IFetch) {
            return $this->service->sum($this);
        } else if(empty($this->where)) {
            return $this->database->query('SHOW TABLE STATUS WHERE Name = "' . $this->table . '"')->fetch()->Rows;
        } elseif (empty($this->join) && empty($this->leftJoin) && empty($this->innerJoin)) {
            return $this->getResource()->count();
        } else {
            $arguments = [];
            foreach ($this->arguments as $key => $argument) {
                is_numeric($key) ? $arguments[] = $argument : null;
            }
            if(null == $this->group && false == $this->database->query($this->sum, ...$arguments)->fetch()) {
                return 1;
            } else if(empty($this->groups)) {
                return $this->database->query($this->sum, ...$arguments)->fetch()->sum;
            } else {
                return $this->database->query($this->sum, ...$arguments)->getRowCount();
            }
        }
    }

    /** @return int */
    public function getSummary() {
        if(!preg_match('/SUM\(/', $summary = $this->columns[$this->getPost('summary')])) {
            $summary = 'SUM(' . $summary . ')';
        }
        $query = preg_replace('/SELECT(.*)FROM/', 'SELECT ' . $summary . ' AS sum FROM', $this->sum);
        if(false == $row = $this->database->query($query, ...$this->arguments)->fetch()) {
            return 0;
        }
        return $row->sum;
    }

    /** @return ActiveRow */
    private function getSetting($type) {
        return $this->database->table($this->config['feeds'])
                        ->where('type', $type)
                        ->where('source', $this->presenter->getName() . ':' . $this->presenter->getAction())
                        ->fetch();
    }

    /** @return string */
    public function getTable() {
        return $this->table;
    }

    /** @return string */
    public function getQuery() {
        return $this->query;
    }

    /** @return IBuilder */
    public function chart(IChart $chart) {
        $this->chart = $chart;
        return $this;
    }

    /** @return IBuilder */
    public function group(array $groups) {
        $this->groups = $groups;
        return $this;
    }

    public function having($having) {
        $this->having = (string) $having;
        return $this;
    }

    /** @return IBuilder */
    public function import(IProcess $import) {
        $this->import = $import;
        return $this;
    }

    /** @return void */
    private function inject($annotation, $column) {
        $annotations = explode('@', $annotation);
        unset($annotations[0]);
        $this->annotations[$column] = isset($this->annotations[$column]) ? $this->annotations[$column] : [];
        foreach ($annotations as $annotationId) {
            if('enum' == $annotationId) {
            } else if ($this->presenter->getName() == $annotationId or $this->presenter->getName() . ':' . $this->presenter->getAction() == $annotationId) {
                $this->annotations[$column]['hidden'] = true;
            } elseif (preg_match('/\(/', $annotationId)) {
                $explode = explode(',', preg_replace('/(.*)\(|\)|\'/', '', $annotationId));
                $this->annotations[$column][preg_replace('/\((.*)/', '', $annotationId)] = array_combine($explode, $explode);
            } elseif (preg_match('/\{.*\}/', $annotationId)) {
                $this->annotations[$column][preg_replace('/\{(.*)\}/', '', $annotationId)] = (array) json_decode('{' . preg_replace('/(.*)\{/', '', $annotationId));
            } else {
                $this->annotations[$column][$annotationId] = true;
            }
        }
        if(true == $this->getAnnotation($column, 'hidden')) {
            unset($this->columns[$column]);
        } else {
            $this->columns[$column] = trim(preg_replace('/\@(.*)/', '', $annotation));
        }
        $this->column($column);
    }

    /** @return IBuilder */
    public function insert(IAdd $add) {
        $this->add = $add;
        return $this;
    }

    /** @return IBuilder */
    public function innerJoin($innerJoin) {
        $this->innerJoin[] = (string) trim($innerJoin);
        return $this;
    }

    /** @return bool */
    public function isEdit() {
        return $this->edit instanceof IEdit;
    }

    /** @return bool */
    public function isChart() {
        return $this->chart instanceof IChart;
    }

    /** @return bool */
    public function isImport() {
        return $this->import instanceof IProcess;
    }

    /** @return bool */
    public function isRemove() {
        return $this->remove instanceof IRemove || true == $this->remove;
    }

    /** @return IBuilder */
    public function join($join) {
        $this->join[] = (string) trim($join);
        return $this;
    }

    /** @return IBuilder */
    public function leftJoin($leftJoin) {
        $this->leftJoin[] = (string) trim($leftJoin);
        return $this;
    }

    /** @return IBuilder */
    public function limit($limit) {
        $this->limit = (int) $limit;
        return $this;
    }

    /** @return IBuilder */
    public function listen(IListener $listener) {
        $this->listener = $listener;
        return $this;
    }

    /** @return ActiveRow */
    private function logQuery($key) {
        if (false == $this->database->table($this->config['spice'])
                        ->where('key', $key)
                        ->fetch()
        ) {
            return $this->database->table($this->config['spice'])
                            ->insert(['key' => $key,
                                'source' => $this->presenter->getName() . ':' . $this->presenter->getAction(),
                                'query' => $this->query,
                                'arguments' => json_encode($this->arguments)]);
        }
    }

    /** @return void */
    public function log($handle) {
        if (isset($this->config['log'])) {
            return $this->database->table($this->config['log'])->insert(['users_id' => $this->presenter->getUser()->getIdentity()->getId(),
                        'source' => $this->presenter->getName() . ':' . $this->presenter->getAction(),
                        'handle' => $handle,
                        'date' => date('Y-m-d H:i:s', strtotime('now'))]);
        }
    }

    /** @return IBuilder */
    public function remove(IRemove $remove) {
        $this->actions['remove'] = 'remove';
        $this->remove = $remove;
        return $this;
    }

    /** @return IBuilder */
    public function row($id, array $row) {
        foreach($row as $column => $status) {
            $value = $this->getPost('add') ? null : $status;
            $label = ucfirst($this->translatorModel->translate($this->table . '.' . $column));
            $attributes =  ['className' => 'form-control', 'name' => intval($id), 'value' => is_null($value) ? '' : $value];
            $this->getAnnotation($column, 'disable') ? $attributes['readonly'] = 'readonly' : null;
            $this->getAnnotation($column, 'onchange') ? $attributes['onChange'] = 'submit' : null;
            if ($this->getAnnotation($column, 'pri')) {
                $this->row->addHidden($column, $value, $attributes);
            } elseif ($this->getAnnotation($column, 'unedit')) {
            } elseif (!empty($default = $this->getAnnotation($column, 'enum'))) {
                $attributes['data'] = [null => $this->translatorModel->translate('--unchosen--')];
                foreach($default as $option => $status) {
                    $attributes['data'][$option] = $this->translatorModel->translate($status);
                }
                $attributes['value'] = '_' . $value;
                $attributes['style'] = ['height' => '100%'];
                $this->row->addSelect($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, ['datetime', 'timestamp'])) {
                $attributes['format'] = $this->config['format']['time']['edit'];
                $attributes['locale'] = preg_replace('/(\_.*)/', '', $this->translatorModel->getLocale());
                $attributes['value'] = is_null($value) ? null : date($this->config['format']['time']['edit'], strtotime($value));
                $this->row->addDateTime($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, ['date'])) {
                $attributes['format'] = $this->config['format']['date']['edit'];
                $attributes['locale'] = preg_replace('/(\_.*)/', '', $this->translatorModel->getLocale());
                $attributes['value'] = is_null($value) ? null : date($this->config['format']['date']['edit'], strtotime($value));
                $this->row->addDateTime($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, 'tinyint') && 1 == $value) {
                $attributes['checked'] = 'checked';
                $this->row->addCheckbox($column, $label, $attributes, []);
            } elseif ($this->getAnnotation($column, 'tinyint')) {
                $this->row->addCheckbox($column, $label, $attributes, []);
            } elseif ($this->getAnnotation($column, 'textarea')) {
                $this->row->addTextArea($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, 'text')) {
                /** @todo https://www.npmjs.com/package/ckeditor-react */
                $this->row->addTextArea($column, $label . ':', $attributes, []);
            } elseif (is_array($value) && $this->getAnnotation($column, 'int')) {
                $attributes['data'] = $value;
                $attributes['style'] = ['height' => '100%'];
                $this->row->addSelect($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, 'addMultiSelect') || (!empty($value) && is_array($value))) {
                $attributes['data'] = $value;
                $this->row->addMultiSelect($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, 'addMultiSelect') && is_string($value)) {
                $attributes['data'] = json_decode($value);
                $this->row->addMultiSelect($column, $label . ':', $attributes, []);
            } elseif (!empty($value) and is_array($value)) {
                $attributes['data'] = $value;
                $attributes['style'] = ['height' => '100%'];
                $this->row->addSelect($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, ['decimal', 'float', 'int'])) {
                $attributes['type'] = 'number';
                $this->row->addText($column, $label . ':', $attributes, []);
            } elseif ($this->getAnnotation($column, 'upload')) {
                $this->row->addUpload($column, $label);
            } elseif ($this->getAnnotation($column, 'multiupload')) {
                $attributes['max'] = $this->config['upload'];
                $this->row->addMultiUpload($column, $label, $attributes, []);
            } else {
                $this->row->addText($column, $label . ':', $attributes, []);
            }
        }
        $this->row->addMessage('_message', $this->translatorModel->translate('Changes were saved.'), ['className' => 'alert alert-success']);
        $this->row->addSubmit('_submit', ucfirst($this->translatorModel->translate('save')),
                    ['className' => 'btn btn-success', 'id' => 'add', 'name' => intval($id), 'onClick' => 'submit']);
        return $this->row;
    }

    /** @return IBuilder */
    public function select(array $columns) {
        $this->columns = $columns;
        return $this;
    }

    /** @return IBuilder */
    public function setConfig($key, $value) {
        $this->config[(string) $key] = $value;
        return $this;
    }

    /** @return void */
    private function setKeys() {
        if(is_array($keys = $this->database->table($this->table)->getPrimary())) {
            foreach($keys as $key) {
                $this->keys[$this->table . '.'  . $key] = $key;
            }
        } else {
            $this->keys = [$this->table . '.'  . $keys => $keys];
        }
    }

    /** @return array*/
    public function submit($submit) {
        $row = $this->getRow();
        if(true == $submit && $this->edit instanceof IEdit) {
            $new = $this->edit->submit($this->primary, $this->getPost('row'));
        } else if(false == $submit && $this->update instanceof IUpdate) {
            $new = $this->update->update($this->getPost('id'), $this->getPost('row'));
        }
        $resource = $this->database->table($this->table);
        if(empty($this->primary)) {
            throw new InvalidStateException('Primary keys were not set.');
        }
        foreach($this->primary as $column => $value) {
            $resource->where($column, $value);
        }
        $resource->update($row);
        foreach($this->where as $column => $value) {
            $resource->where($column, $value);
        }
        if(isset($new)) {
            return $new;
        } else if(false == $resource->fetch()) {
            return [];
        } else {
            return $this->getPost('row');
        }
    }

    /** @return IBuilder */
    public function table($table) {
        $this->table = Types::string($table);
        return $this;
    }
    
    /** @return IBuilder */
    public function update(IUpdate $update) {
        $this->update = $update;
        return $this;
    }

    /** @return array */
    public function validate() {
        $validators = [];
        $row = $this->getRow();
        foreach($row as $column => $value) {
            if($this->getAnnotation($column, 'unedit')) {
            } else if($this->getAnnotation($column, 'required') && empty($value)) {
                $validators[$column] = ucfirst($this->translatorModel->translate($column)) . ' ' . $this->translatorModel->translate('is required.');
            } else if($this->getAnnotation($column, 'uni')) {
                $resource = $this->database->table($this->table);
                foreach($this->primary as $primary => $id) {
                    $resource->where($primary . ' !=', $id);
                }
                if($resource->where($column, $value)->fetch() instanceof ActiveRow) {
                    $validators[$column] =  ucfirst($this->translatorModel->translate('unique item'))  . ' ' . $this->translatorModel->translate($column) . ' ' . $this->translatorModel->translate('already defined in source table.');
                }
            } else if($this->getAnnotation($column, 'email') && Validators::isEmail($value)) {
                $validators[$column] = $this->translatorModel->translate($column) . ' ' . $this->translatorModel->translate('is not valid email.');
            } else if($this->getAnnotation($column, ['int', 'decimal', 'double', 'float']) && !is_numeric($value)) {
                $validators[$column] = $this->translatorModel->translate($column) . ' ' . $this->translatorModel->translate('is not valid number.');
            }
        }
        return $validators;
    }

    /** @return IBuilder */
    public function where($key, $column = null, $condition = null) {
        if(is_bool($condition) and false == $condition) {
        } elseif ('?' == $column and isset($this->where[$key])) {
            $this->arguments[$key] = $this->where[$key];
        } elseif (is_string($condition)) {
            $this->where[preg_replace('/(\s+\?.*|\?.*)/', '', $key)] = $column . $condition;
        } elseif (is_bool($condition) and true == $condition and is_array($column)) {
            $this->where[$key] = $column;
            $this->annotations[preg_replace('/(.*)\./', '', $key)]['enum'] = array_combine($column, $column);
        } elseif (is_callable($column) and false != $value = $column()) {
            $this->where[$key] = $value;
        } elseif (is_array($column) and isset($this->columns[preg_replace('/(.*)\./', '', $key)])) {
            $this->where[$key] = $column;
            $this->annotations[preg_replace('/(.*)\./', '', $key)]['enum'] = array_combine($column, $column);
        } elseif (is_string($column) or is_numeric($column) or is_array($column)) {
            $this->where[$key] = $column;
        } elseif (null === $column) {
            $this->where[] = $key;
        } elseif (null === $condition) {
            $this->where[] = $key;
        }
        return $this;
    }

    /** @return IBuilder */
    public function order(array $order) {
        foreach($order as $column => $value) {
            if(!isset($this->columns[$column])) {
                throw new InvalidStateException('You muse define order column ' . $column . ' in select method of Masala\IBuilder.');
            } else if('DESC' != $value && 'ASC' != $value) {
                throw new InvalidStateException('Order value can be only DESC or ASC.');
            }
        }
        $this->order = $order;
        return $this;
    }

    /** @return IBuilder */
    public function setRow($primary, $data) {
        $this->database->table($this->table)
                ->wherePrimary($primary)
                ->update($data);
        return $this;
    }

    /** @return IBuilder */
    public function process(IProcess $service) {
        $this->service = $service;
        return $this;
    }

    public function prepare() {
        if(null == $filters = $this->getPost('filters')) {
            $filters = [];
        }
        if(isset($filters['groups'])) {
            $this->group = rtrim($filters['groups'], '_');
            unset($filters['groups']);
        } else if(!empty($this->groups)) {
            $this->group = 0;
        }
        if(empty($sort = $this->getPost('sort')) && null == $this->order) {
            foreach($this->columns as $name => $column) {
                if(false == $this->getAnnotation($name, 'unrender')) {
                    $sort = [$name => 'DESC'];
                    break;
                }
            }            
        } else if(null == $sort) {
            $sort = $this->order;
        }
        $this->sort = '';
        foreach($sort as $order => $sorted) {
            $this->sort .= ' ' . $order . ' ' . strtoupper($sorted) . ', ';
        }
        if(!is_numeric($offset = $this->getPost('offset'))) {
            $offset = 1;
        }
        foreach ($filters as $column => $value) {
            $key = preg_replace('/\s(.*)/', '', $column);
            if(is_array($value) && [""] != $value && !empty($value)) {
                foreach($value as $underscoreId => $underscore) {
                    $value[$underscoreId] = ltrim($value[$underscoreId], '_');
                }
                $this->where[$this->columns[$key]] = $value;
                continue;
            } else if([""] == $value || empty($value)) {
                continue;
            }
            $value = ltrim(preg_replace('/\;/', '', htmlspecialchars($value)), '_');
            if(is_array($subfilters = $this->getAnnotation($column, 'filter'))) {
                foreach ($subfilters as $filter) {
                    $this->where[$filter . ' LIKE'] = '%' . $value . '%';
                }
            } elseif (preg_match('/\s\>\=/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->where[$this->columns[$key] . ' >='] = date($this->config['format']['date']['query'], $converted);
            } elseif (preg_match('/\s\>\=/', $column)) {
                $this->where[$this->columns[$key] . ' >='] = $value;
            } elseif (preg_match('/\s\<\=/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*\./', $value)) {
                $this->where[$this->columns[$key] . ' <='] = date($this->config['format']['date']['query'], $converted);
            } elseif (preg_match('/\s\<\=/', $column)) {
                $this->where[$this->columns[$key] . ' <='] = $value;
            } elseif (preg_match('/\s\>/', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->where[$this->columns[$key] . ' >'] = date($this->config['format']['date']['query'], $converted);
            } elseif (preg_match('/\s\>/', $column)) {
                $this->where[$this->columns[$key] . ' >'] = $value;
            } elseif (preg_match('/\s\</', $column) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->where[$this->columns[$key] . ' <'] = date($this->config['format']['date']['query'], $converted);
            } elseif (preg_match('/\s\</', $column)) {
                $this->where[$this->columns[$key] . ' <'] = $value;
            } elseif (preg_match('/\(/', $this->columns[$column]) && (bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value))) {
                $this->having .= $column . ' = "' . $value . '" AND ';
            } elseif (preg_match('/\(/', $this->columns[$column]) && is_numeric($value)) {
                $this->having .= $column . ' = ' . $value . ' AND ';
            } elseif (preg_match('/\(/', $this->columns[$column])) {
                $this->having .= $column . ' LIKE "%' . $value . '%" AND ';
            } elseif ((bool) strpbrk($value, 1234567890) && is_int($converted = strtotime($value)) && preg_match('/\-|.*\..*/', $value)) {
                $this->where[$this->columns[$column]] = date($this->config['format']['date']['query'], $converted);
            } elseif (is_numeric($value)) {


                $this->where[$this->columns[$column]] = $value;
            } else {
                $this->where[$this->columns[$column] . ' LIKE'] = '%' . $value . '%';
            }
        }
        if($this->filter instanceof IFilter) {
            $this->where = $this->filter->filter($this->where);
        }
        /** where and having */
        $where = (!empty($this->where)) ? ' WHERE ' : '';
        foreach ($this->where as $column => $value) {
            $column = (preg_match('/\./', $column) or is_numeric($column)) ? $column : '`' . $column . '`';
            if (is_numeric($column)) {
                $where .= ' ' . $value . ' AND ';
            } elseif (is_array($value) and preg_match('/\sIN|\sNOT/', strtoupper($column))) {
                $where .= ' ' . $column . ' (?) AND ';
                $this->arguments[] = $value;
            } elseif (!is_array($value) and preg_match('/(>|<|=|\sLIKE|\sIN|\sIS|\sNOT|\sNULL|\sNULL)/', strtoupper($column))) {
                $where .= ' ' . str_replace('`', '', $column) . ' ? AND ';
                $this->arguments[] = $value;
            } elseif (is_array($value) && empty($value)) {
                $where .= ' ' . $column . ' IS NULL AND ';
            } elseif (is_array($value)) {
                $where .= ' ' . $column . ' IN (?) AND ';
                $this->arguments[] = $value;
            } else {
                $where .= ' ' . $column . ' = ? AND ';
                $this->arguments[] = $value;
            }
        }
        /** group, having */
        $this->having = rtrim($this->having, 'AND ');
        $this->query .= rtrim($where, 'AND ');
        $this->sum .= rtrim($where, 'AND ');
        if(isset($this->groups[$this->group])) {
            $this->query .= ' GROUP BY ' . $this->groups[$this->group] . ' ';
            $this->sum .= ' GROUP BY ' . $this->groups[$this->group] . ' ';
        }
        if(!empty($this->having)) {
            $this->query .= ' HAVING ' . $this->having . ' ';
            $this->sum .= ' HAVING ' . $this->having . ' ';
        }
        /** offset */
        if(empty($status = $this->getPost('status'))) {
            $this->offset = ($offset - 1) * $this->config['pagination'];
            $this->limit = $this->config['pagination'];
        } else {
            if(in_array($status, ['excel', 'export'])) {
                $this->limit = $this->export->speed($this->config['speed']);
            } else if('import' == $status) {
                $this->limit = $this->import->speed($this->config['speed']);
            } else {
                $this->limit = $this->service->speed($this->config['speed']);
            }
            $this->offset = $offset;
            $this->sort = '';
            foreach($this->keys as $primary => $value) {
                $this->sort .= $primary . ' ASC, ';
            }
        }
        /** sort */
        $this->sort = rtrim($this->sort, ', ');
        if(!empty($this->sort)) {
            $this->query .= ' ORDER BY ' . $this->sort . ' ';
        }
        return $this;
    }

    /** @return bool */
    private function sanitize($column) {
        return 1  == sizeof($joined = explode('.', (string) $column)) ||
            preg_match('/\(|\)/', $column) ||
            (empty($joins = $this->join + $this->leftJoin + $this->innerJoin)) ||
            (substr_count(implode('', $joins), $joined[0]) > 0);
    }

    /** @return string */
    public function translate($name, $annotation) {
        if ($this->presenter->getName() . ':' . $this->presenter->getAction() . ':' . $name != $label = $this->translatorModel->translate($this->presenter->getName() . ':' . $this->presenter->getAction() . ':' . $name)) {
        } elseif ($this->presenter->getName() . ':' . $name != $label = $this->translatorModel->translate($this->presenter->getName() . ':' . $name)) {
        } elseif ($annotation != $label = $this->translatorModel->translate($annotation)) {
        } elseif ($label = $this->translatorModel->translate($name)) {
        }
        return $label;
    }

}
