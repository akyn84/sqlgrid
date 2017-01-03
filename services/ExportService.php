<?php

namespace Masala;

use Nette\Database\Table\ActiveRow,
    Nette\Localization\ITranslator,
    Nette\Application\UI\Presenter,
    Nette\Http\IRequest,
    Sunra\PhpSimple\HtmlDomParser;

class ExportService implements IProcessService {

    /** @var Array */
    private $setting;

    /** @var HtmlDomParser */
    private $dom;

    /** @var ITranslator */
    private $translatorModel;

    /** @var string */
    private $directory;

    /** @var string */
    private $link;
    
    public function __construct($tempDir, HtmlDomParser $dom, ITranslator $translatorModel, IRequest $request) {
        $this->dom = $dom;
        $this->translatorModel = $translatorModel;
        $this->directory = $tempDir;
        $url = $request->getUrl();
        $this->link = $url->scheme . '://' . $url->host . '/' . $url->scriptPath;
    }

    /** getters */
    public function getSetting() {
        return $this->setting;
    }

    /** setters */
    public function setSetting(ActiveRow $setting) {
        $this->setting = $setting;
        return $this;
    }

    /** process methods */
    public function prepare(IMasalaFactory $masala) {
        $sum = $masala->getGrid()->getSum();
        $header = array_values($masala->getGrid()->getOffset($masala->getGrid()->getHash() . ':0', 'export'));
        /*$html = $this->dom->str_get_html($row);
        $header = '';
        foreach($html->find('td') as $column) {
            $header .= preg_replace('/(.*)grid-col-|\>(.*)|\"|\'/', '', $column) . ',';
        }*/
        $folder = $this->directory . '/' . $masala->getName() . '/export';
        !file_exists($folder) ? mkdir($folder, 0755, true) : null;
        $file = $folder . '/' . md5($masala->presenter->getName() . $masala->presenter->getAction() . $masala->presenter->getUser()->getIdentity()->getId()) . '.csv';
        file_put_contents($file, $header);
        return $sum;
    }

    public function run(Array $row, Array $rows, IMasalaFactory $masala) {
        $folder = $this->directory . '/' . $masala->getName() . '/export';
        $file = $folder . '/' . md5($masala->presenter->getName() . $masala->presenter->getAction() . $masala->presenter->getUser()->getIdentity()->getId()) . '.csv';
        $handle = fopen($file, 'a');
        fputs($handle, PHP_EOL . implode(',', $row) . ',');
        fclose($handle);
        $rows['status'] = 'export';
        return $rows;
    }

    public function done(Array $rows, Presenter $presenter) {
        return ['status'=>'export'];
    }

    public function message(IMasalaFactory $masala) {
        $link = $this->link . 'temp/' . $masala->getName() . '/export/' . md5($masala->presenter->getName() . $masala->presenter->getAction() . $masala->presenter->getUser()->getIdentity()->getId()) . '.csv';
        return '<a class="noajax" href="' . $link . '">' . $this->translatorModel->translate('Click here to download your file.') . '<a/>';
    }

}
