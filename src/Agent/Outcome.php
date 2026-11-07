<?php

namespace Lampwick\Laravel\Agent;

enum Outcome
{
    case Sent;
    case Retry;
    case Split;
    case Dropped;
}
