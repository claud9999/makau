<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\GameOn;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class NextPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 35,
            type: StateType::GAME,
            descriptionMyTurn: clienttranslate('next player')
        );
    }

    public function onEnteringState(int $activePlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        // $game->notify->all('Debug', 'actPlay(' . json_encode($cardIds) . ', ' . json_encode($jokers) . ", {$currentPlayerId}, {$rankDemand}, {$suitDemand})");
        $st = json_decode($globals->get('state'), true);

        $handCards = $cards->countItemsInLocation(['hand', $activePlayerId]);
        if ($handCards == 0) {
            ////// WINNNNNNNNNNNN

            // Note: no point saving the current state, it will be reset below.

            $st['playerName'] = $game->getPlayerNameById($activePlayerId);
            $game->notify->all('WinHand', clienttranslate('${playerName} wins this hand!'), $st);

            $playerScore = $this->bga->playerScore;
            $playerScore->inc($activePlayerId, 1);
            if ($playerScore->get($activePlayerId) > 9) {
                return GameOver::class;
            }

            $st = $game->reset();

            $globals->set('state', json_encode($st));

            // vvvvvv parameters not saved but sent to the client
            $st['reshuffled'] = 1;

            $st['_private'] = [];
            for ($i = 0; $i < count($st['playerIds']); $i++) {
                $playerId = $st['playerIds'][$i];

                $st["hand{$playerId}"] = $cards->countItemsInLocation(['hand', $playerId]);

                $st["_private"][$playerId] = [
                    'hand' => $cards->getItemsInLocation(['hand', $playerId])->values()
                ];
            }

            $st['playerId'] = $activePlayerId;
            $st['discard'] = $cards->getItemsInLocation('discard')->values();

            $game->notify->all('NewHand', clienttranslate('The deck is shuffled and a new hand dealt to each player.'), $st);
        }

        if ($st['reverse'])
            $playerId = $this->game->activePrevPlayer();
        else
            $playerId = $this->game->activeNextPlayer();

        $this->game->giveExtraTime($playerId);

        return GameOn::class;
    }
}
