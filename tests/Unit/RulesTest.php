<?php

declare(strict_types=1);

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Lines;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\Rules\DebugCall;
use OffsetSmoker\LaravelKit\Review\Rules\EnvOutsideConfig;
use OffsetSmoker\LaravelKit\Review\Rules\MassAssignment;
use OffsetSmoker\LaravelKit\Review\Rules\MissingAuthorization;
use OffsetSmoker\LaravelKit\Review\Rules\RawSqlInterpolation;
use OffsetSmoker\LaravelKit\Review\Rules\Secrets;
use OffsetSmoker\LaravelKit\Review\Rules\UnlockedLivewireId;
use OffsetSmoker\LaravelKit\Review\StagedFile;

/**
 * A staged file whose every line is new.
 */
function newFile(string $path, string $contents): StagedFile
{
    $count = count(Lines::split($contents));

    return new StagedFile($path, $contents, range(1, max(1, $count)));
}

/**
 * @return list<string>
 */
function rulesHit(Rule $rule, StagedFile $file): array
{
    return array_map(static fn (Finding $finding): string => $finding->rule.'@'.$finding->line, $rule->check($file));
}

describe('mass assignment', function (): void {
    it('flags unguarded models and whole-request writes', function (string $line): void {
        expect(rulesHit(new MassAssignment, newFile('app/Models/Post.php', $line)))->toBe(['mass-assignment@1']);
    })->with([
        'empty guarded' => ['protected $guarded = [];'],
        'array() guarded' => ['protected $guarded = array();'],
        'unguard' => ['Model::unguard();'],
        'create all' => ['Post::create($request->all());'],
        'update all' => ['$post->update( $request->all() );'],
        'fill helper' => ['$post->fill(request()->all());'],
        'facade' => ['Post::updateOrCreate(Request::all());'],
        'input()' => ['$post->forceFill($request->input());'],
    ]);

    it('accepts explicit fillable and validated input', function (string $line): void {
        expect(rulesHit(new MassAssignment, newFile('app/Models/Post.php', $line)))->toBe([]);
    })->with([
        'fillable' => ["protected \$fillable = ['title'];"],
        'guarded star' => ["protected \$guarded = ['*'];"],
        'validated' => ['Post::create($request->validated());'],
        'only' => ["\$post->update(\$request->only(['title']));"],
        'single input' => ["\$post->update(['title' => \$request->input('title')]);"],
    ]);

    it('ignores lines the change did not add', function (): void {
        $file = new StagedFile('app/Models/Post.php', "<?php\nprotected \$guarded = [];\n", [1]);

        expect((new MassAssignment)->check($file))->toBe([]);
    });

    it('ignores files that are not PHP', function (): void {
        expect((new MassAssignment)->check(newFile('docs/notes.md', 'protected $guarded = [];')))->toBe([]);
    });
});

describe('raw sql', function (): void {
    it('flags interpolated or concatenated values', function (string $line): void {
        expect(rulesHit(new RawSqlInterpolation, newFile('app/Reports.php', $line)))->toBe(['raw-sql@1']);
    })->with([
        'double quoted' => ['DB::select("select * from users where id = $id");'],
        'braces' => ['DB::statement("delete from posts where team_id = {$team->id}");'],
        'whereRaw concat' => ['->whereRaw(\'name like \' . $term)'],
        'concat no spaces' => ['->orderByRaw(\'created_at \'.$direction)'],
        'leading variable' => ['DB::raw($column . \' desc\')'],
        'sprintf' => ['->selectRaw(sprintf(\'count(%s)\', $column))'],
        'connection' => ['DB::connection(\'reporting\')->select("select * from t where x = $x")'],
    ]);

    it('accepts bindings and constant SQL', function (string $line): void {
        expect(rulesHit(new RawSqlInterpolation, newFile('app/Reports.php', $line)))->toBe([]);
    })->with([
        'bindings' => ['DB::select(\'select * from users where id = ?\', [$id]);'],
        'named bindings' => ['->whereRaw(\'lower(email) = :email\', [\'email\' => $email])'],
        'constant raw' => ['DB::raw(\'count(*) as total\')'],
        'not raw' => ['->where("name", $name)'],
        'interpolation before the call' => ['$label = "for $name"; $q->whereRaw(\'1 = 1\');'],
    ]);
});

describe('env outside config', function (): void {
    it('flags env() in application code', function (): void {
        expect(rulesHit(new EnvOutsideConfig, newFile('app/Services/Mailer.php', '$key = env(\'MAIL_KEY\');')))
            ->toBe(['env-outside-config@1']);
    });

    it('flags a namespaced env() call', function (): void {
        expect(rulesHit(new EnvOutsideConfig, newFile('routes/web.php', 'if (\\env(\'DEBUG\')) {}')))
            ->toBe(['env-outside-config@1']);
    });

    it('allows env() in config files and ignores look-alikes', function (string $path, string $line): void {
        expect(rulesHit(new EnvOutsideConfig, newFile($path, $line)))->toBe([]);
    })->with([
        'config file' => ['config/services.php', "'key' => env('MAIL_KEY'),"],
        'method' => ['app/Kernel.php', '$app->env(\'local\');'],
        'static' => ['app/Kernel.php', 'App::env();'],
        'property' => ['app/Kernel.php', '$this->env = 1;'],
        'getenv' => ['app/Kernel.php', 'getenv(\'HOME\');'],
        'definition' => ['app/helpers.php', 'function env($key) {}'],
    ]);
});

describe('debug calls', function (): void {
    it('flags dd, dump and ray', function (string $line): void {
        expect(rulesHit(new DebugCall, newFile('app/Foo.php', $line)))->toBe(['debug-call@1']);
    })->with(['dd($user);', 'dump($x);', 'ray($x)->red();', 'var_dump($x);', '\\dd($x);']);

    it('ignores methods and other names', function (string $line): void {
        expect(rulesHit(new DebugCall, newFile('app/Foo.php', $line)))->toBe([]);
    })->with(['$collection->dump();', 'add($x);', 'public function dump(): void', 'Str::dd();']);
});

describe('secrets', function (): void {
    it('flags known token shapes without printing them', function (string $line): void {
        $findings = (new Secrets)->check(newFile('app/Services/Client.php', $line));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->rule)->toBe('secret')
            ->and($findings[0]->snippet)->toBeNull()
            ->and($findings[0]->message)->not->toContain(trim($line));
    })->with([
        'aws' => ['$key = "AKIAABCDEFGHIJKLMNOP";'],
        'github' => ['$t = "ghp_'.str_repeat('a', 36).'";'],
        'stripe' => ['$s = "sk_live_'.str_repeat('9', 24).'";'],
        'anthropic' => ['$k = "sk-ant-'.str_repeat('x', 30).'";'],
        'private key' => ['-----BEGIN RSA PRIVATE KEY-----'],
        'assigned literal' => ["'client_secret' => 'q8Zr2LmX4vNw',"],
    ]);

    it('flags staged env and key files by name', function (string $path): void {
        $findings = (new Secrets)->check(newFile($path, 'APP_KEY=base64:abc'));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->line)->toBeNull();
    })->with(['.env', '.env.production', 'deploy/.env.local', 'storage/oauth-private.key', 'certs/client.p12']);

    it('judges a .pem file by what it holds, not by its name', function (string $contents, array $expected): void {
        expect(rulesHit(new Secrets, newFile('certs/server.pem', $contents)))->toBe($expected);
    })->with([
        'public certificate' => ["-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIU\n-----END CERTIFICATE-----", []],
        'private key' => ["-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG\n-----END PRIVATE KEY-----", ['secret@1']],
    ]);

    it('allows examples, env lookups and test passwords', function (string $path, string $line): void {
        expect((new Secrets)->check(newFile($path, $line)))->toBe([]);
    })->with([
        'env example' => ['.env.example', 'STRIPE_SECRET=sk_test_placeholder'],
        'config lookup' => ['config/services.php', "'secret' => env('STRIPE_SECRET'),"],
        'factory password' => ['database/factories/UserFactory.php', "'password' => 'password-for-tests',"],
        'test' => ['tests/Feature/LoginTest.php', "'password' => 'correct-horse',"],
        'short value' => ['app/Foo.php', "'token' => 'abc',"],
        'variable' => ['app/Foo.php', "'password' => \$password,"],
    ]);
});

describe('missing authorization', function (): void {
    $controller = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        final class PostController extends Controller
        {
            public function update(Post $post): RedirectResponse
            {
                $post->delete();
            }
        }
        PHP;

    it('flags a new controller action in a file that never authorizes', function () use ($controller): void {
        expect(rulesHit(new MissingAuthorization, newFile('app/Http/Controllers/PostController.php', $controller)))
            ->toBe(['missing-authorization@7']);
    });

    it('accepts any authorization in the file', function (string $check) use ($controller): void {
        $file = newFile('app/Http/Controllers/PostController.php', str_replace('$post->delete();', $check, $controller));

        expect((new MissingAuthorization)->check($file))->toBe([]);
    })->with([
        'authorize' => ["\$this->authorize('update', \$post);"],
        'gate' => ["Gate::authorize('update', \$post);"],
        'can' => ["abort_unless(\$request->user()->can('update', \$post), 403);"],
        'middleware' => ["\$this->middleware('can:update,post');"],
        'form request' => ['(UpdatePostRequest $request)'],
    ]);

    it('ignores the base controller and lifecycle methods', function (): void {
        $base = newFile('app/Http/Controllers/Controller.php', "<?php\nabstract class Controller\n{\n    public function helper() {}\n}");
        $render = newFile('app/Livewire/Counter.php', "<?php\nfinal class Counter extends Component\n{\n    public function render() {}\n    public function __construct() {}\n}");

        expect((new MissingAuthorization)->check($base))->toBe([])
            ->and((new MissingAuthorization)->check($render))->toBe([]);
    });

    it('explains that middleware does not cover Livewire actions', function (): void {
        $component = "<?php\nuse Livewire\\Component;\n\nfinal class DeletePost extends Component\n{\n    public function delete(): void {}\n}";
        $findings = (new MissingAuthorization)->check(newFile('app/View/DeletePost.php', $component));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->message)->toContain('Route middleware does not run on Livewire updates');
    });

    it('ignores code outside controllers and components', function (): void {
        expect((new MissingAuthorization)->check(newFile('app/Services/Billing.php', "<?php\npublic function charge() {}")))->toBe([]);
    });
});

describe('unlocked livewire ids', function (): void {
    it('flags public id properties without #[Locked]', function (string $property): void {
        $file = newFile('app/Livewire/EditPost.php', "<?php\nfinal class EditPost extends Component\n{\n    {$property}\n}");

        expect(rulesHit(new UnlockedLivewireId, $file))->toBe(['unlocked-livewire-id@4']);
    })->with(['public int $postId;', 'public $id;', 'public ?string $team_id = null;', 'public int|string $ownerId;']);

    it('accepts locked properties and non-id properties', function (string $code): void {
        $file = newFile('app/Livewire/EditPost.php', "<?php\nfinal class EditPost extends Component\n{\n{$code}\n}");

        expect((new UnlockedLivewireId)->check($file))->toBe([]);
    })->with([
        'locked above' => ["    #[Locked]\n    public int \$postId;"],
        'locked inline' => ['    #[Locked] public int $postId;'],
        'locked with another attribute between' => ["    #[Locked]\n    #[Url]\n    public int \$postId;"],
        'not an id' => ['    public string $title = \'\';'],
        'idea is not an id' => ['    public string $idea = \'\';'],
    ]);

    it('does not borrow a #[Locked] from an earlier property', function (): void {
        $code = "<?php\nfinal class EditPost extends Component\n{\n    #[Locked]\n    public int \$teamId;\n    public int \$postId;\n}";

        expect(rulesHit(new UnlockedLivewireId, newFile('app/Livewire/EditPost.php', $code)))->toBe(['unlocked-livewire-id@6']);
    });

    it('only applies to Livewire components', function (): void {
        expect((new UnlockedLivewireId)->check(newFile('app/Models/Post.php', "<?php\npublic int \$postId;")))->toBe([]);
    });
});
