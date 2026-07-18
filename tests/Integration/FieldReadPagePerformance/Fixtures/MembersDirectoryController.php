<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

final class MembersDirectoryController
{
    public function index(EntityTypeManager $entityTypeManager, AccountInterface $account, Environment $twig): Response
    {
        $repository = $entityTypeManager->getRepository('user');
        $ids = $repository->getQuery()
            ->setAccount($account)
            ->sort('uid', 'ASC')
            ->range(1, FieldReadPageCorpus::MEMBER_COUNT)
            ->execute();

        $members = [];
        foreach ($repository->findMany($ids) as $entity) {
            if (!$entity instanceof User) {
                throw new \LogicException('Members directory received a non-User entity.');
            }
            $members[] = [
                'id' => $entity->id(),
                'name' => $entity->getName(),
            ];
        }

        return new Response($twig->render('members.html.twig', ['members' => $members]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
