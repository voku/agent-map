<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use voku\AgentMap\Move\ClassMovePlan;
use InvalidArgumentException;
use voku\AgentMap\Plan\GovernedPlan;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanMove;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Removal\ClassConstantRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\PropertyRemovalPlan;
use voku\AgentMap\Rename\ClassConstantRenamePlan;
use voku\AgentMap\Rename\ClassRenamePlan;
use voku\AgentMap\Rename\FunctionRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\ParameterRenamePlan;
use voku\AgentMap\Rename\PropertyRenamePlan;
use voku\AgentMap\Plan\PlanProvenance;

/**
 * The governed plan family is one contract with several concrete shapes.
 *
 * Individual planners prove their own semantics; this pins the envelope they all have to agree on,
 * so a new plan type cannot quietly invent its own status vocabulary, duplicate provenance at the
 * top level again, or drop the evidence fields a mutation host validates against.
 */
final class PlanContractShapeTest extends TestCase
{
    /** @var list<class-string<GovernedPlan>> */
    private const PLAN_CLASSES = [
        ClassRenamePlan::class,
        ClassConstantRenamePlan::class,
        FunctionRenamePlan::class,
        MethodRenamePlan::class,
        ParameterRenamePlan::class,
        PropertyRenamePlan::class,
        MethodRemovalPlan::class,
        PropertyRemovalPlan::class,
        ClassConstantRemovalPlan::class,
        ClassMovePlan::class,
    ];

    /** @var list<string> */
    private const REQUIRED_CONSTRUCTOR_PARAMETERS = [
        'status',
        'targetId',
        'provenance',
        'edits',
        'blindSpots',
        'staleEvidence',
        'blockers',
        'notObservable',
    ];

    /** @var list<string> */
    private const REQUIRED_MACHINE_KEYS = [
        'type',
        'contract_version',
        'status',
        'target_id',
        'provenance',
        'edits',
        'blind_spots',
        'stale_evidence',
        'blockers',
        'not_observable',
    ];

    public function testEveryPlanSharesTheGovernedStatusVocabulary(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            $reflection = new ReflectionClass($planClass);
            self::assertSame('safe', $reflection->getConstant('STATUS_SAFE'), $planClass);
            self::assertSame('review_required', $reflection->getConstant('STATUS_REVIEW_REQUIRED'), $planClass);
            self::assertSame('blocked', $reflection->getConstant('STATUS_BLOCKED'), $planClass);
            self::assertIsString($reflection->getConstant('CONTRACT_VERSION'), $planClass);
            self::assertMatchesRegularExpression('/^\d+\.\d+$/', (string) $reflection->getConstant('CONTRACT_VERSION'), $planClass);
        }
    }

    public function testEveryPlanCarriesOneProvenanceTypeAndTheSharedEvidenceFields(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            $reflection = new ReflectionClass($planClass);
            $constructor = $reflection->getConstructor();
            self::assertNotNull($constructor, $planClass);

            $parameters = [];
            foreach ($constructor->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }

            foreach (self::REQUIRED_CONSTRUCTOR_PARAMETERS as $required) {
                self::assertArrayHasKey($required, $parameters, $planClass . ' must expose ' . $required);
            }

            $provenanceType = $parameters['provenance']->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $provenanceType, $planClass);
            self::assertSame(PlanProvenance::class, $provenanceType->getName(), $planClass . ' must reuse the single provenance type');
        }
    }

    public function testMachineProjectionsShareOneEnvelopeAndNoDuplicatedProvenance(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            $plan = $this->plan($planClass);
            $payload = $plan->toArray();

            foreach (self::REQUIRED_MACHINE_KEYS as $key) {
                self::assertArrayHasKey($key, $payload, $planClass . ' must project ' . $key);
            }

            // Provenance identity lives in exactly one place; the pre-0.9 top-level aliases are gone.
            self::assertArrayNotHasKey('backend', $payload, $planClass);
            self::assertArrayNotHasKey('map_digest', $payload, $planClass);

            self::assertSame(
                ['map_digest', 'backend', 'analysis_fingerprint'],
                array_keys((array) $payload['provenance']),
                $planClass,
            );
            self::assertSame((string) (new ReflectionClass($planClass))->getConstant('CONTRACT_VERSION'), $payload['contract_version'], $planClass);
            self::assertMatchesRegularExpression('/^[a-z_]+_plan$/', (string) $payload['type'], $planClass);
        }
    }

    public function testBlockedIsTheOneStatusThatSuppressesMutationEvidence(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            $plan = $this->plan($planClass, PlanStatus::BLOCKED);
            self::assertTrue($plan->isBlocked(), $planClass);

            $safe = $this->plan($planClass, PlanStatus::SAFE);
            self::assertFalse($safe->isBlocked(), $planClass);
        }
    }

    public function testABlockedPlanCannotBeConstructedWithEditsAtAll(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            try {
                $this->plan($planClass, PlanStatus::BLOCKED, [$this->edit()]);
                self::fail($planClass . ' constructed a blocked plan carrying an edit.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must publish no applicable mutation', $exception->getMessage(), $planClass);
            }
        }
    }

    public function testABlockedPlanCannotBeConstructedWithMovesEither(): void
    {
        $withMoves = array_values(array_filter(
            self::PLAN_CLASSES,
            fn (string $planClass): bool => $this->acceptsMoves($planClass),
        ));
        self::assertNotSame([], $withMoves, 'At least one contract must carry moves for this to mean anything.');

        foreach ($withMoves as $planClass) {
            try {
                $this->plan($planClass, PlanStatus::BLOCKED, [], [$this->move()]);
                self::fail($planClass . ' constructed a blocked plan carrying a move.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must publish no applicable mutation', $exception->getMessage(), $planClass);
            }
        }
    }

    public function testTheGuardOnlyRejectsBlockedPlans(): void
    {
        // Without this, a guard that rejected everything would pass the two tests above.
        foreach (self::PLAN_CLASSES as $planClass) {
            foreach ([PlanStatus::SAFE, PlanStatus::REVIEW_REQUIRED] as $status) {
                $moves = $this->acceptsMoves($planClass) ? [$this->move()] : [];
                $plan = $this->plan($planClass, $status, [$this->edit()], $moves);

                $payload = $plan->toArray();
                self::assertSame($status, $payload['status'], $planClass);
                self::assertCount(1, (array) $payload['edits'], $planClass);
                if ($moves !== []) {
                    self::assertCount(1, (array) $payload['moves'], $planClass);
                }
            }
        }
    }

    public function testAMoveCannotNameAPathOutsideTheProjectRoot(): void
    {
        $escapes = [
            '../outside/Target.php',
            '/etc/agent-map/Target.php',
            'C:/agent-map/Target.php',
            'src/../../Target.php',
            '',
        ];

        foreach ($escapes as $toPath) {
            try {
                new PlanMove(
                    fromPath: 'src/Target.php',
                    toPath: $toPath,
                    sourceSha256: 'sha256:0',
                    reason: 'escape probe',
                );
                self::fail('PlanMove represented a destination outside the project root: ' . $toPath);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must stay inside the project root', $exception->getMessage(), $toPath);
            }
        }

        // A backslash-separated path is the same path, so it cannot be a way around the rule.
        $this->expectException(InvalidArgumentException::class);
        new PlanMove(fromPath: 'src/Target.php', toPath: '..\\outside\\Target.php', sourceSha256: 'sha256:0', reason: 'escape probe');
    }

    public function testAnUnknownStatusIsRejectedRatherThanCarried(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            try {
                $this->plan($planClass, 'probably_fine');
                self::fail($planClass . ' accepted a status outside the governed vocabulary.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('unknown plan status', $exception->getMessage(), $planClass);
            }
        }
    }

    public function testTheProjectedTypeComesFromTheDeclaredContractIdentity(): void
    {
        foreach (self::PLAN_CLASSES as $planClass) {
            $declared = (new ReflectionClass($planClass))->getConstant('PLAN_TYPE');
            self::assertIsString($declared, $planClass);
            self::assertSame($declared, $this->plan($planClass, PlanStatus::SAFE)->toArray()['type'], $planClass);
        }
    }

    /**
     * @param class-string<GovernedPlan> $planClass
     * @param list<PlanEdit> $edits
     * @param list<PlanMove> $moves
     */
    private function plan(string $planClass, string $status = PlanStatus::SAFE, array $edits = [], array $moves = []): GovernedPlan
    {
        $reflection = new ReflectionClass($planClass);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor, $planClass);

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = match ($parameter->getName()) {
                'status' => $status,
                'edits' => $edits,
                'moves' => $moves,
                default => $this->emptyArgument($parameter, $planClass),
            };
        }

        $plan = $reflection->newInstanceArgs($arguments);
        self::assertInstanceOf(GovernedPlan::class, $plan, $planClass);

        return $plan;
    }

    /** @param class-string<GovernedPlan> $planClass */
    private function acceptsMoves(string $planClass): bool
    {
        $constructor = (new ReflectionClass($planClass))->getConstructor();
        self::assertNotNull($constructor, $planClass);

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === 'moves') {
                return true;
            }
        }

        return false;
    }

    private function edit(): PlanEdit
    {
        return new PlanEdit(
            path: 'src/Target.php',
            sourceSha256: 'sha256:0',
            startFilePos: 10,
            endFilePos: 15,
            lineStart: 2,
            lineEnd: 2,
            expected: 'oldName',
            replacement: 'newName',
            role: 'contract_shape_probe',
            symbolId: 'class:Demo\\Target',
            resolution: 'parser_resolved',
        );
    }

    private function move(): PlanMove
    {
        return new PlanMove(
            fromPath: 'src/Target.php',
            toPath: 'src/Moved/Target.php',
            sourceSha256: 'sha256:0',
            reason: 'contract shape probe',
        );
    }

    private function emptyArgument(ReflectionParameter $parameter, string $planClass): mixed
    {
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type, $planClass . '::$' . $parameter->getName());

        if ($type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'string' => '',
            'int' => 0,
            'array' => [],
            PlanProvenance::class => new PlanProvenance('sha256:0', 'test-backend', null),
            default => self::fail($planClass . ' uses an unexpected contract type: ' . $type->getName()),
        };
    }
}
