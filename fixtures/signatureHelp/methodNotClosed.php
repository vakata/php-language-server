<?php

class HelpClass2
{
    protected function method(string $param = "")
    {
    }
    public function test()
    {
        $this->method(1,1);
    }
}
$a = new HelpClass2;
$a
    ->method(
        1,
        array(),
