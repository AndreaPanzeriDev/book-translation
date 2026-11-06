<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony imposta "en-us,en" come Accept-Language predefinito nelle
        // richieste di test. L'app parte in italiano: i test che vogliono
        // verificare un'altra lingua inviano un header esplicito.
        $this->withHeader('Accept-Language', 'it');
    }
}
