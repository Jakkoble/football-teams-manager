<?php

namespace App\Controller;

use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject\Player;
use Pimcore\Model\DataObject\Team;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Component\Routing\Annotation\Route;

class TeamController extends FrontendController
{
    #[Route('/', name: 'team_overview', methods: ['GET'])]
    #[Template('team/list.html.twig')]
    public function listAction()
    {
        $allTeams = new Team\Listing();

        // only fetch players once instead for each team a new query
        $players = new Player\Listing();

        $rows = [];

        foreach ($allTeams as $team) {
            $playerCount = count($players->filterByTeam($team));
            $rows[] = [
                'team' => $team,
                'playerCount' => $playerCount
            ];
        }

        return ['rows' => $rows];
    }

    #[Route('/{id}', name: 'team_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Template('team/detail.html.twig')]
    public function detailAction(int $id)
    {
        $team = Team::getById($id);
        if (!$team) throw $this->createNotFoundException('Team does not exist');
        $players = $team->getPlayers();

        return [
            'team' => $team,
            'players' => $players
        ];
    }
}
