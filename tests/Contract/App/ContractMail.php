<?php

namespace Lampwick\Laravel\Tests\Contract\App;

use Illuminate\Mail\Mailable;

final class ContractMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('Contract mail')->html('<p>Hello</p>');
    }
}
