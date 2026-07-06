<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Ssr;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\GenealogyLocalDemoMarkers;
use Waaseyaa\Genealogy\Service\GenealogyFamilyService;
use Waaseyaa\Genealogy\Service\GenealogyPedigreeService;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;

/**
 * @api
 */
final class GenealogySsrController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly Environment $twig,
        private readonly GateInterface $gate,
        private readonly GenealogyPedigreeService $pedigree,
        private readonly GenealogyFamilyService $familyService,
    ) {}

    /**
     * Public landing page for genealogy SSR (no product opt-in gate).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function landing(array $params, array $query, AccountInterface $account, Request $request): Response
    {
        $demo = $this->resolveLocalDemoLinks();
        $html = $this->twig->render('genealogy_landing.html.twig', [
            'demo_person_id' => $demo['person_id'],
            'demo_family_id' => $demo['family_id'],
            'authenticated' => $account->isAuthenticated(),
            'genealogy_product_enabled' => $this->genealogyProductEnabled($account),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function person(array $params, array $query, AccountInterface $account, Request $request): Response
    {
        if (!$this->genealogyProductEnabled($account)) {
            return new Response('Not Found', 404);
        }

        $id = isset($params['id']) ? (string) $params['id'] : '';
        $person = $this->pedigree->loadPerson($id);
        if ($person === null || !$this->gate->allows('view', $person, $account)) {
            return new Response('Not Found', 404);
        }

        // Gather the relationship TOPOLOGY in system context (no account ⇒
        // accessCheck(false)), then conceal per-person via the gate in
        // neighborSlots(). The concealment is name-level: a relative the viewer
        // cannot see renders as a redacted placeholder ("Private relative", a
        // single uniform label so the slot leaks neither the name nor the
        // concealed person's living/deceased status), never by name and never
        // with a loadable id. We
        // must NOT pass the account into the id-gathering query: under
        // deny-by-default (audit C-6) the wired relationship policy drops every
        // edge with a non-viewable endpoint, which would make living/private
        // relatives vanish entirely instead of showing the intended placeholder.
        $html = $this->twig->render('genealogy_person.html.twig', [
            'person' => $person,
            'parent_neighbors' => $this->pedigree->neighborSlots($this->pedigree->parentPersonIds($id), $account, $this->gate),
            'child_neighbors' => $this->pedigree->neighborSlots($this->pedigree->childPersonIds($id), $account, $this->gate),
            'spouse_neighbors' => $this->pedigree->neighborSlots($this->pedigree->spousePersonIds($id), $account, $this->gate),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function family(array $params, array $query, AccountInterface $account, Request $request): Response
    {
        if (!$this->genealogyProductEnabled($account)) {
            return new Response('Not Found', 404);
        }

        $id = isset($params['id']) ? (string) $params['id'] : '';
        // C-22 WP3: read path now goes through the canonical repository.
        $family = $this->entityTypeManager->getRepository('genealogy_family')->find($id);
        if (!$family instanceof GenealogyFamily || !$this->gate->allows('view', $family, $account)) {
            return new Response('Not Found', 404);
        }

        $html = $this->twig->render('genealogy_family.html.twig', [
            'family' => $family,
            // Topology in system context; per-person gate concealment (see person()).
            'member_neighbors' => $this->pedigree->neighborSlots($this->familyService->memberPersonIds($id), $account, $this->gate),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function ancestorChart(array $params, array $query, AccountInterface $account, Request $request): Response
    {
        if (!$this->genealogyProductEnabled($account)) {
            return new Response('Not Found', 404);
        }

        $id = isset($params['id']) ? (string) $params['id'] : '';
        $person = $this->pedigree->loadPerson($id);
        if ($person === null || !$this->gate->allows('view', $person, $account)) {
            return new Response('Not Found', 404);
        }

        $html = $this->twig->render('genealogy_ancestor_chart.html.twig', [
            'person' => $person,
            'generations' => $this->pedigree->ancestorGenerationsRedacted($id, $account, $this->gate),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function genealogyProductEnabled(AccountInterface $account): bool
    {
        if (!$account->isAuthenticated()) {
            // Anonymous SSR is allowed only when the genealogy access policy grants
            // published tree + entity visibility (see GenealogyContentAccessPolicy).
            return true;
        }
        // Built-in server dev fallback: authenticated but not a persisted User row.
        if ($account instanceof DevAdminAccount) {
            return true;
        }
        if (!$account instanceof User) {
            return false;
        }

        $v = $account->get('genealogy_product_enabled');

        return $v === true || $v === 1 || $v === '1';
    }

    /**
     * @return array{person_id: ?string, family_id: ?string}
     */
    private function resolveLocalDemoLinks(): array
    {
        // C-22 WP2: the query builder now lives on the repository.
        $personIds = $this->entityTypeManager->getRepository('genealogy_person')->getQuery()
            ->condition('display_name', GenealogyLocalDemoMarkers::CHILD_PERSON_DISPLAY)
            // system context: local-demo link discovery runs without an account in scope
            ->accessCheck(false)
            ->range(0, 1)
            ->execute();
        $personId = isset($personIds[0]) ? (string) $personIds[0] : null;

        $familyIds = $this->entityTypeManager->getRepository('genealogy_family')->getQuery()
            ->condition('display_name', GenealogyLocalDemoMarkers::FAMILY_DISPLAY)
            // system context: local-demo link discovery runs without an account in scope
            ->accessCheck(false)
            ->range(0, 1)
            ->execute();
        $familyId = isset($familyIds[0]) ? (string) $familyIds[0] : null;

        return ['person_id' => $personId, 'family_id' => $familyId];
    }
}
