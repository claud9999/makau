<?php
declare(strict_types=1);
namespace Bga\Games\makaucloudnein\States;

use Bga\GameFramework\StateType;
use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\States\GameState;

class EndHand extends GameState
{
  public function __construct(protected Game $game)
  {
    parent::__construct(
      $game,
      id: 40,
      type: StateType::GAME,
      description: "",
    );
  }

  public function onEnteringState()
  {
    // TODO: implement logic
    return NewHand::class;
  }
}
