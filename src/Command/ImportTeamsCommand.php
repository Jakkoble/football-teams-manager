<?php

namespace App\Command;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Data\GeoCoordinates;
use Pimcore\Model\Element\Service;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:import-teams',
    description: 'Imports all the teams from an excel spreadsheet.'
)]
class ImportTeamsCommand extends Command
{
    // team column indexes
    private const TEAM_ID = 0;
    private const TEAM_NAME = 1;
    private const TEAM_TRAINER = 2;
    private const TEAM_LOCATION = 3;
    private const TEAM_LATITUDE = 4;
    private const TEAM_LONGITURE = 5;
    private const TEAM_FOUNDED = 6;
    private const TEAM_DESCRIPTION = 7;
    private const TEAM_LOGO_PATH = 8;

    // player column indexes
    private const PLAYER_ID = 0;
    private const PLAYER_FIRST_NAME = 1;
    private const PLAYER_LAST_NAME = 2;
    private const PLAYER_NUMBER = 3;
    private const PLAYER_BIRTHDAY = 4;
    private const PLAYER_POSITION = 5;
    private const PLAYER_TEAM_ID = 6;

    private OutputInterface $logger;
    private ValidatorInterface $validator;

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger = $output;
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $output->writeln([
            'Import Teams',
            '============',
            '',
        ]);

        $asset = Asset::getByPath('/data.xlsx');
        if (!$asset) {
            $output->writeln('Error: Asset "data.xlsx" does not exist in assets directory!');
            return Command::INVALID;
        }

        $path = PIMCORE_PROJECT_ROOT . '/public/var/assets' . $asset->getFullPath();
        if (!file_exists($path)) {
            $output->writeln("Error: File at $path does not exist!");
            return Command::INVALID;
        }

        // initialize document reader
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadEmptyCells(false);
        $reader->setIgnoreRowsWithNoCells(true);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['teams', 'players']);
        $sheets = $reader->load($path);

        $teamSheet = $this->getSheet('teams', $sheets);
        $playerSheet = $this->getSheet('players', $sheets);
        if (!$teamSheet || !$playerSheet) return;

        $teamsDir = $this->getObjectDirectory('/Teams');
        $rows = $teamSheet->toArray();
        array_shift($rows); // remove label row

        // Stores all processed teams to later find match for each player
        $processedTeams = [];

        foreach ($rows as $row) {
            $team = $this->transformTeamData($row);
            if (!$team) {
                $this->logger->writeln("Skipped this object!");
                continue;
            }

            $teamId = $team->teamId;
            $team = $this->createOrUpdateTeam($team, $teamsDir);
            $processedTeams[$teamId] = $team;
        }

        $playersDir = $this->getObjectDirectory('/Players');
        $rows = $playerSheet->toArray();
        array_shift($rows); // remove label row

        foreach ($rows as $row) {
            $player = $this->transformPlayerData($row);
            if (!$player) {
                $this->logger->writeln("Skipped this object!");
                continue;
            }

            $this->createOrUpdatePlayer($player, $playersDir, $processedTeams);
        }

        return Command::SUCCESS;
    }

    private function getSheet(string $sheetName, Spreadsheet $sheets): ?Worksheet
    {
        if (!$sheets->sheetNameExists($sheetName)) {
            $this->logger->writeln("Error: data.xslx does not contain a $sheetName sheet");
            return null;
        }

        return $sheets->getSheetByName($sheetName);
    }

    private function getObjectDirectory(string $path)
    {
        $dir = DataObject\Folder::getByPath($path);
        if ($dir) return $dir;

        $dir = new DataObject\Folder();
        $dir->setKey(basename($path));
        $dir->setParent(DataObject::getById(1));
        $dir->save();

        return $dir;
    }

    private function transformTeamData(array $row): ?TeamInput
    {
        $input  = new TeamInput(
            teamId: $row[self::TEAM_ID] ?? null,
            name: $row[self::TEAM_NAME] ?? null,
            trainer: $row[self::TEAM_TRAINER] ?? null,
            location: $row[self::TEAM_LOCATION] ?? null,
            latitude: $row[self::TEAM_LATITUDE] ?? null,
            longitude: $row[self::TEAM_LONGITURE] ?? null,
            founded: $row[self::TEAM_FOUNDED] ?? null,
            description: $row[self::TEAM_DESCRIPTION] ?? null,
            logoPath: $row[self::TEAM_LOGO_PATH] ?? null,
        );

        $errors = $this->validator->validate($input);
        if (count($errors) > 0)
            return $this->printValidationError("Team {$input->name}" . $errors->get(0)->getMessage());

        return $input;
    }


    private function transformPlayerData(array $row): ?PlayerInput
    {
        $input = new PlayerInput(
            playerId: $row[self::PLAYER_ID] ?? null,
            firstName: $row[self::PLAYER_FIRST_NAME] ?? null,
            lastName: $row[self::PLAYER_LAST_NAME] ?? null,
            number: $row[self::PLAYER_NUMBER] ?? null,
            birthday: $row[self::PLAYER_BIRTHDAY] ?? null,
            position: $row[self::PLAYER_POSITION] ?? null,
            teamId: $row[self::PLAYER_TEAM_ID] ?? null,
        );

        $errors = $this->validator->validate($input);
        if (count($errors) > 0)
            return $this->printValidationError("Player {$input->getFullName()}" . $errors->get(0)->getMessage());

        return $input;
    }

    private function printValidationError(string $message): null
    {
        $this->logger->writeln("Error | Validation for row failed: $message");
        return null;
    }

    private function createOrUpdateTeam(TeamInput $data, $teamsDir)
    {
        $name = $data->name;

        // https://docs.pimcore.com/platform/Pimcore/Objects/Working_with_PHP_API/#get-objects-matching-a-value-of-a-property
        $existingTeam = DataObject\Team::getByName($name, ['limit' => 1]);
        if ($existingTeam) {
            $team = $existingTeam;
            $this->logger->writeln("Overwriting team $name...");
        } else {
            $team = new DataObject\Team();
            $team->setKey(Service::getValidKey($name, 'object'));
            $team->setParent($teamsDir);
            $this->logger->writeln("Creating new team $name...");
        }

        $team->setName($name);
        $team->setTrainer($data->trainer);
        $team->setLocation($data->location);
        $team->setFounded($data->founded);
        $team->setDescription($data->description);

        // create coordinates of lat and lon
        $gps = new GeoCoordinates((float)$data->latitude, (float)$data->longitude);
        $team->setCoordinates($gps);

        // fetch logo if path is present
        if (!empty($data->logoPath)) {
            $logoPath = $data->logoPath;
            $logo = Asset::getByPath($logoPath);
            if ($logo instanceof Asset\Image)
                $team->setLogo($logo);
            else
                $this->logger->writeln("Warning: Logo not found at $logoPath");
        }

        $team->setPublished(true);
        $team->save();

        $this->logger->writeln("Team $name saved!");
        return $team;
    }

    private function createOrUpdatePlayer(PlayerInput $data, $playersDir, $processedTeams)
    {
        $playerQuery = new DataObject\Player\Listing();
        $playerQuery->setCondition(
            "
            firstName = :firstName AND
            lastName = :lastName AND
            number = :number AND
            position = :position",
            [
                "firstName" => $data->firstName,
                "lastName" => $data->lastName,
                "number" => $data->number,
                "position" => $data->position,
            ]
        );
        $playerQuery->load();

        if ($playerQuery->getCount() > 0) {
            $player = $playerQuery->current();
            $this->logger->writeln("Overwriting Player {$data->getFullName()}...");
        } else {
            $player = new DataObject\Player();
            $player->setKey(Service::getValidKey($data->getFullName(), 'object'));
            $player->setParent($playersDir);
            $this->logger->writeln("Creating new Player {$data->getFullName()}...");
        }

        $player->setFirstName($data->firstName);
        $player->setLastName($data->lastName);
        $player->setNumber((float)$data->number);
        $player->setBirthday(Carbon::instance(SharedDate::excelToDateTimeObject($data->birthday)));
        $player->setPosition($data->position);

        if (is_numeric($data->teamId) && !empty($processedTeams[$data->teamId])) {
            $team = $processedTeams[$data->teamId];
            $player->setTeam($team);
        }

        $player->setPublished(true);
        $player->save();

        $this->logger->writeln("Player {$data->getFullName()} saved!");
    }
}


// Validation classes
// https://symfony.com/doc/current/validation

class TeamInput
{
    public function __construct(
        #[Assert\NotBlank(message: "id cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "id must be a number")]
        public mixed $teamId,

        #[Assert\NotBlank(message: "name cannot be empty")]
        public ?string $name,

        #[Assert\NotBlank(message: "trainer cannot be empty")]
        public ?string $trainer,

        #[Assert\NotBlank(message: "location cannot be empty")]
        public ?string $location,

        #[Assert\NotBlank(message: "latitude cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "latitude must be numeric")]
        public mixed $latitude,

        #[Assert\NotBlank(message: "longitude cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "longitude must be numeric")]
        public mixed $longitude,

        #[Assert\NotBlank(message: "founding year cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "founding year must be numeric")]
        public mixed $founded,

        #[Assert\NotBlank(message: "description cannot be empty")]
        public ?string $description,

        public ?string $logoPath = null,
    ) {}
}

class PlayerInput
{
    public function __construct(
        #[Assert\NotBlank(message: "id cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "id must be a number")]
        public mixed $playerId,

        #[Assert\NotBlank(message: "first name cannot be empty")]
        public ?string $firstName,

        #[Assert\NotBlank(message: "second name cannot be empty")]
        public ?string $lastName,

        #[Assert\NotBlank(message: "player number cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "player number must be numeric")]
        public mixed $number,

        #[Assert\NotBlank(message: "birthday cannot be empty")]
        #[Assert\Type(type: 'numeric', message: "birthday must be numeric")]
        public mixed $birthday,

        #[Assert\NotBlank(message: "position cannot be empty")]
        public ?string $position,

        #[Assert\Type(type: 'numeric', message: "team id must be numeric")]
        public mixed $teamId
    ) {}

    public function getFullName()
    {
        return $this->firstName . " " . $this->lastName;
    }
}
