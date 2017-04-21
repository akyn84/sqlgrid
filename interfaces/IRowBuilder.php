<?php

namespace Masala;

interface IRowBuilder {

    /** @return int */
    function add(Array $data);

    /** @return int */
    function delete();
    
    /** @return string */
    function getSpice();
    
    /** @return int */
    function update(Array $data);
    
    /** @return array */
    function getColumns();

}
