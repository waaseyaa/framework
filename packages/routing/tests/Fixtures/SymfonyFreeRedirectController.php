<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Fixtures;

use Waaseyaa\Routing\Controller;
use Waaseyaa\Routing\RedirectResponse;

final class SymfonyFreeRedirectController extends Controller
{
    public function store(): RedirectResponse
    {
        return $this->redirectToRoute('todo.show', ['todo' => 42], 303);
    }
}
