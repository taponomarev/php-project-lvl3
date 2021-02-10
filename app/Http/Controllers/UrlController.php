<?php

namespace App\Http\Controllers;

use DiDom\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class UrlController extends Controller
{
    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index()
    {
        $subQuery = 'SELECT url_id, status_code, updated_at, MAX(id) FROM url_checks GROUP BY url_id';
        $urls = DB::table('urls', 'u')
            ->leftJoinSub($subQuery, 'ch1', 'u.id', '=', 'ch1.url_id')
            ->latest()
            ->paginate(15);
        return view('urls.index', ['urls' => $urls]);
    }

    /**
     * @param string $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function show(string $id)
    {
        $url = DB::table('urls')->find($id);
        $urlChecks = DB::table('url_checks')
            ->where('url_id', $id)
            ->get()
            ->reverse();
        return view('urls.show', ['url' => $url, 'urlChecks' => $urlChecks]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function store(Request $request)
    {
        $request->validate([
            'urls.name' => 'required|active_url',
        ]);

        /** @var string[] */
        $urlInfo = parse_url($request->get('urls')['name']);
        $normalizeUrl = "{$urlInfo['scheme']}://{$urlInfo['host']}";

        $urlData = DB::table('urls')->where('name', $normalizeUrl)->first();

        if (!empty($urlData)) {
            flash('Site already exists!')->error();
            return \redirect('/');
        }

        $todayCarbonDate = now();
        DB::table('urls')->insert([
            'name' => $normalizeUrl,
            'created_at' => $todayCarbonDate,
            'updated_at' => $todayCarbonDate
        ]);

        flash("Url '{$normalizeUrl}' added successfully!")->success();
        return redirect(route('urls'));
    }

    /**
     * @param string $url_id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \DiDom\Exceptions\InvalidSelectorException
     */
    public function storeCheck(string $url_id)
    {
        $site = DB::table('urls')->find($url_id);
        $response = Http::get($site->name);
        $nowDate = now();

        [$h1, $description, $keywords] = $this->getParsedData($response->body());

        DB::table('url_checks')->insert([
            'url_id' => $url_id,
            'status_code' => $response->status(),
            'h1' => $h1,
            'description' => $description,
            'keywords' => $keywords,
            'created_at' => $nowDate,
            'updated_at' => $nowDate
        ]);

        flash("The Site has been verified successfully!")->success();
        return redirect(route('urls.show', $url_id));
    }

    /**
     * @param string $html
     * @return array|\Illuminate\Contracts\Foundation\Application|RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \DiDom\Exceptions\InvalidSelectorException
     */
    public function getParsedData(string $html)
    {
        $document = new Document();
        try {
            $document->loadHtml($html);

            $h1 = optional($document->first('h1'), function ($node) {
                return $node->text();
            });

            $description = optional($document->first('meta[name="description]'), function ($node) {
                return $node->getAttribute('content');
            });

            $keywords = optional($document->first('meta[name="keywords]'), function ($node) {
                return $node->getAttribute('content');
            });

            return [
                $h1,
                $description,
                $keywords
            ];
        } catch (\Exception $exception) {
            flash("The site not available")->success();
            return redirect(back());
        }
    }
}
