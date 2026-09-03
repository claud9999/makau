<?php
declare(strict_types=1);
namespace Bga\Games\makaucloudnein\States;

use Bga\GameFramework\StateType;
use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\States\GameState;

class NextPlayer extends GameState
{
  public function __construct(protected Game $game)
  {
    parent::__construct(
      $game,
      id: 32,
      type: StateType::GAME,
    );
  }

  public function onEnteringState()
  {
    $game = $this->game;

    if ($game->cards->countItemsInLocation('hand') == 0) {
      return EndHand::class;
    }
    
    $player_id = $game->activeNextPlayer();
    $game->giveExtraTime($player_id);
    return PlayerTurn::class;
  }
}
