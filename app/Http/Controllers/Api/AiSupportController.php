<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiSupportController extends Controller
{
    private bool $isMember = false;

    public function handle(Request $request)
    {
        $questionRaw = (string) $request->input('message', '');
        $question = $this->cleanInput($questionRaw);

        if ($question === '') {
            return response()->json([
                'status' => 'ok',
                'reply' => 'Please type your question so I can help.',
                'quick_replies' => $this->defaultQuickReplies(),
                'product_cards' => [],
                'brand_cards' => [],
                'category_cards' => [],
                'brand_view_all_url' => '',
            ]);
        }

        $qLower = mb_strtolower($question, 'UTF-8');
        $qNormSimple = $this->normalizeSimple($qLower);
        $searchQuestion = $this->normalizeSearchQuery($question);
        $nameQuery = $this->normalizeNameQuery($searchQuestion);
        $strictFromQuery = $this->detectStrictNameQuery($qLower, $searchQuestion);
        if ($strictFromQuery !== '') {
            $nameQuery = $strictFromQuery;
        }
        $isStrictNameQuery = in_array(strtolower(trim($nameQuery)), ['bed', 'pillow', 'sofa', 'bench', 'mirror'], true);

        foreach ($this->tagalogIntentAliases() as $pattern => $append) {
            if (preg_match($pattern, $question)) {
                $qLower .= $append;
            }
        }

        $memberId = 0;
        try {
            $sessionMember = (int) $request->session()->get('MM_mem_ctr', 0);
            if ($sessionMember > 0) {
                $memberId = $sessionMember;
            }
        } catch (\Throwable) {
            $memberId = 0;
        }
        if ($memberId <= 0 && $request->user()) {
            $memberId = (int) ($request->user()->c_userid ?? $request->user()->id ?? 0);
        }
        $isMember = $memberId > 0;
        if (! $isMember) {
            $headerFlag = strtolower(trim((string) $request->header('X-AF-IS-MEMBER', '')));
            if ($headerFlag === '1' || $headerFlag === 'true' || $headerFlag === 'yes') {
                $isMember = true;
            }
        }
        $this->isMember = $isMember;

        $reply = '';
        $quickReplies = $this->defaultQuickReplies();
        $productCards = [];
        $brandCards = [];
        $categoryCards = [];
        $brandViewAllUrl = '';

        $detectedBrand = $this->detectBrand($qLower);
        $detectedBrandId = (int) ($detectedBrand['id'] ?? 0);
        $detectedBrandName = (string) ($detectedBrand['name'] ?? '');

        try {
            $faq = $this->faqMap();
            if (array_key_exists($qNormSimple, $faq)) {
                $reply = $faq[$qNormSimple];
                $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
            } else {
                $matchedFaq = '';
                foreach ($faq as $key => $ans) {
                    if ($key !== '' && str_contains($qNormSimple, $key)) {
                        $matchedFaq = $ans;
                        break;
                    }
                }

                if ($matchedFaq !== '') {
                    $reply = $matchedFaq;
                    $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
                } else {
                    if (preg_match('/\b(how are you|how\'?s it going|kamusta ka|kumusta ka|ayos ka ba|mabuti ka ba)\b/i', $qLower)) {
                        $greetReplies = [
                            'I am doing great and ready to help. What do you need today?',
                            'I am good, thanks for asking. How can I assist you today?',
                            'Mabuti ako at handang tumulong. Ano ang kailangan mo ngayon?',
                            'Ayos ako, salamat! Paano kita matutulungan?'
                        ];
                        $reply = $greetReplies[array_rand($greetReplies)];
                        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(are you there|are you available|are you working|nandyan ka ba|available ka ba|gumagana ka ba|active ka ba)\b/i', $qLower)) {
                        $availReplies = [
                            'Yes, I am here and active. You can ask me about products, shipping, payments, and orders.',
                            'Yes, I am available now. Send your question and I will help right away.',
                            'Oo, nandito ako at active. Tanong ka lang tungkol sa products, shipping, payments, at orders.',
                            'Oo, available ako ngayon. Sabihin mo lang ang tanong mo.'
                        ];
                        $reply = $availReplies[array_rand($availReplies)];
                        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
                    } elseif ($this->isDiningTableIntent($qLower)) {
                        $diningIntent = $this->handleDiningTableIntent($detectedBrandId, $question);
                        $reply = $diningIntent['reply'];
                        $quickReplies = $diningIntent['quickReplies'];
                        $productCards = $diningIntent['productCards'];
                    } elseif (preg_match('/\b(hi|hello|hey|hi there|assistant|chatbot|ai|good morning|good afternoon|good evening|kamusta|kumusta|magandang umaga|magandang hapon|magandang gabi|magandang araw)\b/i', $qLower) || mb_strlen($question, 'UTF-8') <= 2) {
                        $helloReplies = [
                            'Hi! Welcome. I can help with product details, shipping, payment options, and order tracking.',
                            'Hello! I am ShopBuddy AI. Ask me anything about products, checkout, delivery, or your orders.',
                            'Hey there! Tell me what you need and I will help right away.',
                            'Welcome! I can assist with products, payments, shipping, and order status.',
                            'Hi! Looking for something specific? I can search products or help with your order.',
                            'Kumusta! Nandito ako para tumulong sa products, shipping, at orders.',
                            'Magandang araw! Ano ang maitutulong ko sa iyo?',
                            'Hi! Maaari kitang tulungan maghanap ng produkto o mag-track ng order.'
                        ];
                        $reply = $helloReplies[array_rand($helloReplies)];
                        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(recommend|recommendation|suggest|suggestion|best match|top pick|top picks|personalized|personalised)\b/i', $qLower)
                        && !preg_match('/\b(best product|best seller|bestseller|top product|recommended product|what is the best product)\b/i', $qLower)) {
                        $recIntent = $this->handleGeneralRecommendationIntent($detectedBrandId, $detectedBrandName);
                        $reply = $recIntent['reply'];
                        $quickReplies = $recIntent['quickReplies'];
                        $productCards = $recIntent['productCards'];
                        $categoryCards = $recIntent['categoryCards'];
                    } elseif ($this->hasBudgetIntent($qLower)) {
                        $budgetFlow = $this->handleBudgetIntent(
                            $question,
                            $qLower,
                            $searchQuestion,
                            $detectedBrandId
                        );
                        $reply = $budgetFlow['reply'];
                        $quickReplies = $budgetFlow['quickReplies'];
                        $productCards = $budgetFlow['productCards'];
                        $categoryCards = $budgetFlow['categoryCards'];
                    } elseif (preg_match('/\b(best product|best seller|bestseller|top product|recommended product|what is the best product)\b/i', $qLower)) {
                        $bestIntent = $this->handleBestProductIntent(
                            $question,
                            $qLower,
                            $searchQuestion,
                            $detectedBrandId,
                            $detectedBrandName
                        );
                        $reply = $bestIntent['reply'];
                        $quickReplies = $bestIntent['quickReplies'];
                        $productCards = $bestIntent['productCards'];
                        $categoryCards = $bestIntent['categoryCards'];
                    } elseif (preg_match('/\b(refund|return|exchange|damaged|damage|defective|wrong item|missing parts)\b/i', $qLower)) {
                        $reply = "Here is the refund/return process:\n"
                            . "1) Go to My Orders.\n"
                            . "2) Select the order and click Request Refund.\n"
                            . "3) Fill out the form and upload clear photos/videos of the item and packaging as proof.\n"
                            . "4) Submit the request. Our team will review it and notify you via notifications or email.";
                        $quickReplies = ['Track my order', 'Contact support', 'Payment methods'];
                    } else {
                        $directNameMatches = [];
                        if (strlen($nameQuery) >= 3) {
                            if ($isStrictNameQuery) {
                                $strictKeywords = $this->getStrictNameKeywords($nameQuery);
                                $merged = [];
                                foreach ($strictKeywords as $keyword) {
                                    $merged = $this->mergeCardLists($merged, $this->searchProductsByNameOnly($keyword, $detectedBrandId, 10));
                                }
                                if (empty($merged)) {
                                    foreach ($strictKeywords as $keyword) {
                                        $merged = $this->mergeCardLists($merged, $this->searchProductsByNameNoPrice($keyword, 10));
                                    }
                                }
                                $directNameMatches = $merged;
                            } else {
                                $directNameMatches = $this->searchProductsByName($nameQuery, $detectedBrandId, 10);
                                if (empty($directNameMatches)) {
                                    $directNameMatches = $this->searchProductsByNameNoPrice($nameQuery, 10);
                                }
                            }
                        }
                        if (!empty($directNameMatches)) {
                            $productCards = $directNameMatches;
                            $reply = 'Here are matching products for "' . $question . '".';
                            $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
                        } else {
                            $keywordMatches = $this->searchProductsByKeywords($searchQuestion, $detectedBrandId, 10);
                            if (!empty($keywordMatches)) {
                                $productCards = $keywordMatches;
                                $reply = 'Here are matching products for "' . $question . '".';
                                $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
                            } else {
                                $matchedCategories = $this->searchCategories($searchQuestion, 6);
                                if (!empty($matchedCategories)) {
                                    $categoryCards = $matchedCategories;
                                    $reply = 'I found matching categories you can browse.';
                                    $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
                                } else {
                                    $specificCards = [];
                                    $tokens = $this->buildSearchTokens($searchQuestion);
                                    if (count($tokens) >= 2 || strlen($searchQuestion) >= 8) {
                                        $specificCards = $this->getExactOrClosestProduct(
                                            $searchQuestion,
                                            $detectedBrandId
                                        );
                                    }

                                    if (!empty($specificCards)) {
                                        $productCards = $specificCards;
                                        $reply = 'Here is the product you searched.';
                                        $quickReplies = ['Product specifications', 'Track my order', 'Contact support'];
                                    } elseif (preg_match('/\b(minimalist|minimalist style)\b/i', $qLower)) {
                        $reply = "Minimalist style focuses on clean lines, neutral tones, and functional pieces.\nRecommended items:\n- Melo Reversible Fabric Sofa Set\n- Orla Fabric Sofa Bed\n- Flow Bench Sofa\n- Simple side tables, shelves, coffee tables, and console tables.";
                        $productCards = $this->getTopicCards(
                            ['minimalist','sofa','bench','coffee table','console table','side table','shelf'],
                            $detectedBrandId,
                            5
                        );
                        $quickReplies = ['Suggest items under PHP 5,000.', 'Can you recommend a sofa for small spaces?', 'Show me trending home decor.'];
                    } elseif (preg_match('/\b(suggest|items?)\b.*\b(under|below|less than)\b.*\b(5000|5,000|php)\b|\bunder\s*5000\b/i', $qLower)) {
                        $productCards = $this->getPriceRangeCards(1, 5000, $detectedBrandId, 8);
                        $reply = !empty($productCards)
                            ? 'Here are available items under PHP 5,000 based on current products.'
                            : 'I could not find available items under PHP 5,000 right now. Please try again later.';
                        $quickReplies = ['What is best for office setup at home?', 'What are your best-selling living room products?', 'Do you have items on sale right now?'];
                    } elseif (preg_match('/\b(office setup|home office|office at home|work from home)\b/i', $qLower)) {
                        $reply = "For a home office setup, good choices are:\n- Affordahome Office Table / Study Table\n- Affordahome Office Chair (A5 / A6)\n- Affordahome Laptop Table\n- Monitor stand and desk organizers.";
                        $productCards = $this->getTopicCards(
                            ['office table','study table','office chair','laptop table','monitor stand','desk organizer'],
                            $detectedBrandId,
                            6
                        );
                        $quickReplies = ['Suggest items under PHP 5,000.', 'What is the highest-rated product?', 'Show me trending home decor.'];
                    } elseif (preg_match('/\b(highest-rated|highest rated|top rated|best rated)\b/i', $qLower)) {
                        $productCards = $this->getTopRatedCards($detectedBrandId, 5);
                        if (!empty($productCards)) {
                            $reply = 'Here are our highest-rated products based on customer reviews.';
                        } else {
                            $productCards = $this->getBestSellingCards($detectedBrandId, 5);
                            $reply = !empty($productCards)
                                ? 'We do not have enough ratings yet, so here are our current best-sellers instead.'
                                : 'I cannot find top-rated products right now. Please try again later.';
                        }
                        $quickReplies = ['What are your best-selling living room products?', 'Can you recommend a sofa for small spaces?', 'Do you have items on sale right now?'];
                    } elseif (preg_match('/\b(low stock|low in stock|stock status|stock)\b/i', $qLower)) {
                        $reply = 'Most items are on-demand, so the majority of products are usually available. For a specific item, send the exact product name and I will check availability.';
                        $quickReplies = ['What is the highest-rated product?', 'Do you have items on sale right now?', 'How can I track my order?'];
                    } elseif (preg_match('/\b(trending home decor|home decor trend|trending decor)\b/i', $qLower)) {
                        $reply = "Trending home decor now:\n- Ceramic vases in neutral tones\n- Abstract wall art and photo frames\n- Minimalist LED desk lamps\n- Indoor planters with stand\n- Textured throw blankets.";
                        $productCards = $this->getTopicCards(
                            ['vase','wall art','photo frame','lamp','planter','throw blanket','decor'],
                            $detectedBrandId,
                            6
                        );
                        $quickReplies = ['What products match a minimalist style?', 'Can you recommend a sofa for small spaces?', 'Suggest items under PHP 5,000.'];
                    } elseif (preg_match('/\b(received the wrong item|wrong item|incorrect item|wrong order)\b/i', $qLower)) {
                        $reply = "We are sorry for the inconvenience. Please send:\n- A photo of the wrong item\n- Your order number\nWe will replace it free of charge or issue a full refund.";
                        $quickReplies = ['What happens if my item arrives damaged?', 'How can I track my order?', 'What courier do you use?'];
                    } elseif (preg_match('/\b(gcash|online banking|bank transfer|credit\/debit|credit card|debit card)\b/i', $qLower)) {
                        $reply = "Yes, we accept:\n- GCash\n- Online Banking / Bank Transfer\n- Credit/Debit Cards\nPayment details are shown at checkout and in your invoice.";
                        $quickReplies = ['How can I track my order?', 'What courier do you use?', 'What if I received the wrong item?'];
                    } elseif (preg_match('/\b(how can i track my order|track my order|order tracking)\b/i', $qLower)) {
                        $reply = "Once shipped, you will receive:\n- A tracking number via SMS or Email\n- Tracking access on the courier website or in your account.";
                        $quickReplies = ['What courier do you use?', 'What happens if my item arrives damaged?', 'Do you accept GCash or online banking?'];
                    } elseif (preg_match('/\b(arrives damaged|damaged item|damaged product)\b/i', $qLower)) {
                        $reply = "We will replace or refund it. Please:\n1. Send a photo of the damaged area.\n2. Provide your order number.\nWe will handle the rest at no extra cost.";
                        $quickReplies = ['What if I received the wrong item?', 'How can I track my order?', 'What courier do you use?'];
                    } elseif (preg_match('/\b(what courier|courier do you use|shipping partner|delivery partner)\b/i', $qLower)) {
                        $reply = "We ship via trusted partners such as:\n- SPX\n- J&T\n- XDE\n- AF Home Fleet\nCourier depends on your location and order size.";
                        $quickReplies = ['How can I track my order?', 'What happens if my item arrives damaged?', 'Do you accept GCash or online banking?'];
                    } elseif (preg_match('/\b(sofa for small spaces|small space sofa|small spaces)\b/i', $qLower)) {
                        $reply = "Best choices for small spaces:\n- Melo Reversible Fabric Sofa Set\n- Orla Fabric Sofa Bed\n- Flow Bench Sofa.";
                        $productCards = $this->getTopicCards(
                            ['melo sofa','orla sofa bed','flow bench sofa','compact sofa','sofa bed'],
                            $detectedBrandId,
                            6
                        );
                        $quickReplies = ['What products match a minimalist style?', 'What are your best-selling living room products?', 'Suggest items under PHP 5,000.'];
                    } elseif (preg_match('/\b(best-selling living room|best selling living room|living room best seller)\b/i', $qLower)) {
                        $reply = "Best-sellers in living room:\n- L-shape sofas and sofa sets\n- Sofa beds\n- Accent chairs\n- Coffee tables\n- Throw pillows.";
                        $productCards = $this->getTopicCards(
                            ['living room','sofa','sofa bed','accent chair','coffee table','throw pillow'],
                            $detectedBrandId,
                            6
                        );
                        $quickReplies = ['Can you recommend a sofa for small spaces?', 'Do you have items on sale right now?', 'What is the highest-rated product?'];
                    } elseif (preg_match('/\b(items on sale|on sale right now|sale right now)\b/i', $qLower)) {
                        $reply = 'We will announce sale promos coming soon.';
                        $quickReplies = ['Suggest items under PHP 5,000.', 'What are your best-selling living room products?', 'Show me trending home decor.'];
                    } elseif (preg_match('/\b(i need some help|help me with something|i have a question|question about a product)\b/i', $qLower)) {
                        $helpReplies = [
                            'Absolutely. I am ready to help. Tell me what you need: product details, shipping, payments, or order tracking.',
                            'Of course. Please share your concern and I will assist right away with products, payments, shipping, or orders.'
                        ];
                        $reply = $helpReplies[array_rand($helpReplies)];
                        $quickReplies = ['Product price', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(authentic|original|genuine)\b/i', $qLower)) {
                        $reply = 'Our products are sourced from authorized suppliers and official brands. For a specific item, share the exact product name and I will help verify its listing details.';
                        $quickReplies = ['Product specifications', 'Warranty', 'Customer reviews'];
                    } elseif (preg_match('/\b(customer reviews?|reviews?|ratings?)\b/i', $qLower)) {
                        $reply = 'You can check product reviews and ratings on the product page. If you share the product name, I can help you open the correct listing.';
                        $quickReplies = ['Product specifications', 'Similar products', 'Best product'];
                    } elseif (preg_match('/\b(show brands?|show brand|list brands?|brand list)\b/i', $qLower) || preg_match('/\b(how many brands?|total number of brands?|different brands?|unique brands?|count of brands?|brand names? does this shop include|distinct brands? does this store stock|number of brands listed|brands can customers choose from|brands are available for purchase|brands do you currently sell|list the number of brands|brand categories are represented|different brand labels|total number of labels\/brands|retrieve the number of brands|brand count .*database|brand entities .*seller|display the total brands|count all brands)\b/i', $qLower)) {
                        $brandCount = $this->getActiveBrandCount();
                        if ($brandCount > 0) {
                            $topBrands = $this->getTopActiveBrands(10);
                            if (!empty($topBrands)) {
                                $brandNames = [];
                                foreach ($topBrands as $br) {
                                    $brandId = (int) ($br['id'] ?? 0);
                                    $brandName = trim((string) ($br['name'] ?? ''));
                                    if ($brandName === '') {
                                        continue;
                                    }
                                    $brandNames[] = $brandName;
                                    $brandUrl = $this->frontendBaseUrl() . '/by-brand';
                                    $brandCards[] = [
                                        'name' => $brandName,
                                        'count' => (int) ($br['product_count'] ?? 0),
                                        'url' => $brandUrl,
                                    ];
                                }
                                $brandViewAllUrl = $this->frontendBaseUrl() . '/all-brands';
                                $reply = 'This shop currently offers ' . $brandCount . ' active brands. Top brands: ' . implode(', ', array_filter($brandNames)) . '.';
                            } else {
                                $reply = 'This shop currently offers ' . $brandCount . ' active brands.';
                            }
                        } else {
                            $reply = 'I cannot find active brand records right now.';
                        }
                        $quickReplies = ['Show brands', 'Best product', 'Show lowest price'];
                    } elseif (preg_match('/\b(can you recommend(?: the)? products?(?: for me)?|recommend (?:a )?product(?: for me)?|do you have products? that you can recommend)\b/i', $qLower)) {
                        $productCards = $this->getBestSellingCards($detectedBrandId, 6);
                        if (!empty($productCards)) {
                            $reply = 'Here are some of the best products in our shop right now.';
                        } else {
                            $reply = 'I can recommend products. Please share your budget and preferred category so I can suggest better matches.';
                        }
                        $quickReplies = ['Best product under PHP 5,000', 'Show lowest price', 'Best product'];
                    } elseif (preg_match('/\b(similar products?|show similar|recommend products?|suggest products?)\b/i', $qLower)) {
                        $reply = 'I can recommend similar products. Share the product name, your budget, and preferred brand so I can suggest better matches.';
                        $quickReplies = ['Best product under PHP 5,000', 'Show lowest price', 'Best product'];
                    } elseif (
                        preg_match('/\b(?:between|from)\s*(?:php|p)?\s*(\d[\d,\.]*)\s*(?:to|and|-)\s*(?:php|p)?\s*(\d[\d,\.]*)\b/i', $question, $mRange)
                        || preg_match('/\b(?:php|p)?\s*(\d[\d,\.]*)\s*(?:to|and|-)\s*(?:php|p)?\s*(\d[\d,\.]*)\b/i', $question, $mRange)
                    ) {
                        $minBudget = (float) str_replace([',', ' '], '', (string) $mRange[1]);
                        $maxBudget = (float) str_replace([',', ' '], '', (string) $mRange[2]);
                        if ($minBudget > 0 && $maxBudget > 0) {
                            $productCards = $this->getPriceRangeCards($minBudget, $maxBudget, $detectedBrandId, 6);
                            $reply = !empty($productCards)
                                ? 'Here are products within your budget range.'
                                : 'I could not find products in that range right now. Try widening the range.';
                        } else {
                            $reply = 'Please enter a valid range like "1,500 to 3,000".';
                        }
                        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
                    } elseif (preg_match('/\b(lower(?:\s+price)?(?:\s+than)?|below(?:\s+price)?|less\s+than|up\s*to)\b/i', $qLower) && preg_match('/(\d[\d,\.]*)/', $question, $mBudget)) {
                        $budget = (float) str_replace([',', ' '], '', (string) $mBudget[1]);
                        if ($budget > 0) {
                            $productCards = $this->getPriceRangeCards(1, $budget, $detectedBrandId, 5);
                            $reply = !empty($productCards)
                                ? 'Here are products lower than your target budget.'
                                : 'I could not find products under that amount right now. Try a slightly higher budget.';
                        } else {
                            $reply = 'Please enter a valid amount like "lower than 1,000".';
                        }
                        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
                    } elseif (preg_match('/\b(what are the specifications|specifications?|specs?|sizes?|colors?|available variants?|what sizes|what colors)\b/i', $qLower)) {
                        $reply = 'For exact specs, sizes, and colors, open the product page and check the variations/details section. If you send the product name, I can guide you faster.';
                        $quickReplies = ['Is this item in stock?', 'Warranty', 'Similar products'];
                    } elseif (preg_match('/\b(in stock|available|stock available|out of stock)\b/i', $qLower)) {
                        $reply = 'Live stock depends on selected variation (size/color). Please select the exact variant on the product page to see current availability.';
                        $quickReplies = ['Product price', 'Sizes and colors', 'Track my order'];
                    } elseif (preg_match('/\b(warranty|guarantee)\b/i', $qLower)) {
                        $reply = 'Warranty coverage depends on product type and brand policy. Please check the product page warranty section or share the product name so I can help you verify.';
                        $quickReplies = ['Product specifications', 'Return policy', 'Contact support'];
                    } elseif (preg_match('/\b(good for|best for|use case|for gaming|for office|for bedroom|for kitchen)\b/i', $qLower)) {
                        $reply = 'I can help match products for your use case. Tell me what you will use it for, your budget, and preferred brand.';
                        $quickReplies = ['Recommend products', 'Best product under PHP 5,000', 'Similar products'];
                    } elseif (preg_match('/\b(best product under|under\s*php?\s*\d+|budget\s*php?\s*\d+)\b/i', $qLower)) {
                        $budget = 0;
                        if (preg_match('/(\d[\d,\.]*)/', $question, $mBudget)) {
                            $budget = (float) str_replace([',', ' '], '', (string) $mBudget[1]);
                        }
                        if ($budget > 0) {
                            $productCards = $this->getPriceRangeCards(1, $budget, $detectedBrandId, 5);
                            $reply = !empty($productCards)
                                ? 'Here are recommended products within your budget.'
                                : 'I could not find products in that budget right now. You can increase the budget slightly and I will suggest more options.';
                        } else {
                            $reply = 'Please share your budget amount (for example: best product under PHP 5,000).';
                        }
                        $quickReplies = ['Show lowest price', 'Best product', 'Similar products'];
                    } elseif (preg_match('/\b(which one is better|better:\s*|compare)\b/i', $qLower)) {
                        $reply = 'I can compare products by price, specifications, and use case. Please provide two exact product names so I can give a clear recommendation.';
                        $quickReplies = ['Product A vs Product B', 'Best product', 'Similar products'];
                    } elseif (preg_match('/\b(trending products?|popular now|best sellers? right now)\b/i', $qLower)) {
                        $productCards = $this->getBestSellingCards($detectedBrandId, 5);
                        $reply = !empty($productCards) ? 'Here are trending products right now.' : 'Trending products are not available at the moment.';
                        $quickReplies = ['Best product', 'Show lowest price', 'Track my order'];
                    } elseif (preg_match('/\b(compatible|compatibility|works with|supported device)\b/i', $qLower)) {
                        $reply = 'Compatibility depends on the exact model/specification. Please share your device/model so I can help check compatibility.';
                        $quickReplies = ['Product specifications', 'Compare products', 'Contact support'];
                    } elseif (preg_match('/\b(what payment methods|payment methods?|accept payment|digital wallets?)\b/i', $qLower)) {
                        $methods = $this->getPaymentMethods();
                        $reply = !empty($methods)
                            ? ('Available payment methods: ' . implode(', ', array_filter($methods)) . '.')
                            : 'Payment method list is currently unavailable. Please check checkout for the latest options.';
                        $quickReplies = ['Is online payment safe?', 'Why was payment declined?', 'Can I pay via COD?'];
                    } elseif (preg_match('/\b(cash on delivery|cod)\b/i', $qLower)) {
                        $reply = 'Cash on Delivery availability depends on your delivery location and current checkout eligibility. Please check checkout for your address.';
                        $quickReplies = ['Payment methods', 'Shipping fee', 'Track my order'];
                    } elseif (preg_match('/\b(online payment safe|secure payment|is payment safe)\b/i', $qLower)) {
                        $reply = 'Yes, online payment is processed through secured payment gateways. Avoid sharing OTP/PIN and only complete payment on official checkout pages.';
                        $quickReplies = ['Payment methods', 'Why was payment declined?', 'Contact support'];
                    } elseif (preg_match('/\b(payment declined|payment failed|declined card|failed payment)\b/i', $qLower)) {
                        $reply = 'Payment may fail due to incorrect details, insufficient balance, bank restrictions, or timeout. Please retry once, then try another payment method if needed.';
                        $quickReplies = ['Payment methods', 'Contact support', 'Track my order'];
                    } elseif (preg_match('/\b(installments?|installment)\b/i', $qLower)) {
                        $reply = 'Installment availability depends on selected payment channel and issuer. Please proceed to checkout to view eligible installment options.';
                        $quickReplies = ['Payment methods', 'Best product under PHP 5,000', 'Contact support'];
                    } elseif (preg_match('/\b(shipping fee|delivery fee|shipping cost|how much shipping)\b/i', $qLower)) {
                        $reply = 'Shipping fee depends on product, weight, courier option, and destination. The exact fee is shown on checkout after selecting delivery details.';
                        $quickReplies = ['How long delivery takes?', 'Track my order', 'Free shipping'];
                    } elseif (preg_match('/\b(how long delivery|delivery time|shipping time|eta)\b/i', $qLower)) {
                        $reply = 'Delivery time depends on your location, courier, and order status. You can track updates using your order number.';
                        $quickReplies = ['Track my order', 'Shipping fee', 'Change delivery address'];
                    } elseif (preg_match('/\b(free shipping)\b/i', $qLower)) {
                        $reply = 'Free shipping may be available during promos or for qualifying orders. Please check current campaign terms at checkout.';
                        $quickReplies = ['Promo codes', 'Shipping fee', 'Track my order'];
                    } elseif (preg_match('/\b(return policy|request a refund|refund|exchange|refund process|return shipping)\b/i', $qLower)) {
                        $reply = 'You can request return, refund, or exchange based on order status and policy eligibility. Refund processing time depends on payment method, and return shipping responsibility depends on the return reason.';
                        $quickReplies = ['I need help with my order', 'Track my order', 'Contact support'];
                    } elseif (preg_match('/\b(payment failing|payment failed|payment secure|secure payment|paypal|credit card|cash on delivery|cod available|pay in installments)\b/i', $qLower)) {
                        $reply = 'If payment fails, please retry once, check card/e-wallet details, and ensure network is stable. Available methods are shown at checkout. We currently support secure checkout with available payment gateways.';
                        $quickReplies = ['Payment methods', 'Track my order', 'Contact support'];
                    } elseif (preg_match('/\b(i need help with my order|talk to a human|human agent|arrived damaged|damaged item|create an account|forgot my password|reset password)\b/i', $qLower)) {
                        $reply = 'I can help route you quickly. For urgent concerns like damaged items or account access, please contact support directly so a human agent can assist you right away.';
                        $quickReplies = ['Contact support', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(best product for my needs|suggest similar|similar products|trending|gift recommendation|gift recommendations|gift)\b/i', $qLower)) {
                        $reply = 'I can recommend products based on your needs. Please share your budget, category, and preferred brand, and I will suggest the best options for you.';
                        $quickReplies = ['Show lowest price', 'Show highest price', 'Best product'];
                    } elseif (preg_match('/\b(payment|pay|method|gcash|maya|grab|card|voucher|cod)\b/i', $qLower)) {
                        $methods = $this->getPaymentMethods();
                        if (!empty($methods)) {
                            $reply = 'Available payment methods: ' . implode(', ', array_filter($methods)) . '.';
                        } else {
                            $reply = 'I can help with payment options. Please check checkout payment methods for the latest list.';
                        }
                        $quickReplies = ['How to use voucher?', 'Track my order', 'Contact support'];
                    } elseif (preg_match('/\b(contact|support|email|phone|hotline)\b/i', $qLower)) {
                        $support = $this->getSupportDetails();
                        $reply = 'Support details: '
                            . ($support['phone'] !== '' ? 'Phone: ' . $support['phone'] . '. ' : '')
                            . ($support['email'] !== '' ? 'Email: ' . $support['email'] . '.' : '');
                        $quickReplies = ['Track my order', 'Payment methods', 'Shipping policy'];
                    } elseif (preg_match('/\b(track|tracking|order status|shipping status|delivery status|where.*order|where.*package|where.*parcel|my order|my package)\b/i', $qLower)) {
                        $orderReply = $this->handleOrderTracking($question, $isMember, $memberId);
                        $reply = $orderReply['reply'];
                        $quickReplies = ['Payment methods', 'Contact support', 'Shipping policy'];
                    } elseif (preg_match('/\b(appliances?|room|tv|television|beedroom|bedroom|bed|pillow|sofa|sofas|tabo|chair|chairs|table|tables|cabinet|cabinets|stool|stools|furniture|living room|dining room|kitchen|bathroom|office|outdoor)\b/i', $qLower)) {
                        $topicTerms = $this->getSearchTermsFromQuery($qLower, $searchQuestion);
                        $productCards = $this->getTopicCards($topicTerms, $detectedBrandId, 20);
                        if (empty($productCards)) {
                            $categoryCards = $this->searchCategories($searchQuestion, 6);
                        }
                        $reply = !empty($productCards)
                            ? 'Here are products that match your request.'
                            : (!empty($categoryCards) ? 'I found matching categories you can browse.' : 'I could not find a matching product right now. Please try a more specific product name.');
                        $quickReplies = ['Show lowest price', 'Show highest price', 'Track my order'];
                    } elseif (preg_match('/\bwhat is the best product here\b/i', $qLower)) {
                        $productCards = $this->getTopRatedCards($detectedBrandId, 5);
                        if (!empty($productCards)) {
                            $reply = $detectedBrandId > 0
                                ? ('Here are some of our highest-rated ' . $detectedBrandName . ' products.')
                                : 'Here are some of our highest-rated products.';
                        } else {
                            $productCards = $this->getBestSellingCards($detectedBrandId, 5);
                            $reply = !empty($productCards)
                                ? 'We do not have enough ratings yet, so here are our current best-sellers instead.'
                                : 'I could not find a matching product right now. Please try a more specific product name.';
                        }
                        $quickReplies = ['Show appliances', 'Show furniture', 'Track my order'];
                    } elseif (preg_match('/\b(lowest|cheapest|budget|low price)\b/i', $qLower)) {
                        $productCards = $this->getPriceSortedCards($detectedBrandId, 'ASC', 5);
                        if (!empty($productCards)) {
                            $reply = $detectedBrandId > 0
                                ? ('Here are some of the lowest-priced ' . $detectedBrandName . ' products.')
                                : 'Here are some of the lowest-priced products.';
                        } else {
                            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
                        }
                        $quickReplies = ['Show highest price', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(highest|expensive|premium|high price)\b/i', $qLower)) {
                        $productCards = $this->getPriceSortedCards($detectedBrandId, 'DESC', 5);
                        if (!empty($productCards)) {
                            $reply = $detectedBrandId > 0
                                ? ('Here are some of the higher-priced ' . $detectedBrandName . ' products.')
                                : 'Here are some of the higher-priced products.';
                        } else {
                            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
                        }
                        $quickReplies = ['Show lowest price', 'Track my order', 'Payment methods'];
                    } elseif (preg_match('/\b(recommend|recommendation|suggest|suggestion|best match|top pick|top picks|personalized|personalised)\b/i', $qLower)
                        && !preg_match('/\b(best product|best seller|bestseller|top product|recommended product|what is the best product)\b/i', $qLower)) {
                        $recIntent = $this->handleGeneralRecommendationIntent($detectedBrandId, $detectedBrandName);
                        $reply = $recIntent['reply'];
                        $quickReplies = $recIntent['quickReplies'];
                        $productCards = $recIntent['productCards'];
                        $categoryCards = $recIntent['categoryCards'];
                    } elseif ($this->hasBudgetIntent($qLower)) {
                        $budgetFlow = $this->handleBudgetIntent(
                            $question,
                            $qLower,
                            $searchQuestion,
                            $detectedBrandId
                        );
                        $reply = $budgetFlow['reply'];
                        $quickReplies = $budgetFlow['quickReplies'];
                        $productCards = $budgetFlow['productCards'];
                        $categoryCards = $budgetFlow['categoryCards'];
                    } elseif (preg_match('/\b(best product|best seller|bestseller|top product|recommended product|what is the best product)\b/i', $qLower)) {
                        $bestIntent = $this->handleBestProductIntent(
                            $question,
                            $qLower,
                            $searchQuestion,
                            $detectedBrandId,
                            $detectedBrandName
                        );
                        $reply = $bestIntent['reply'];
                        $quickReplies = $bestIntent['quickReplies'];
                        $productCards = $bestIntent['productCards'];
                        $categoryCards = $bestIntent['categoryCards'];
                    } elseif (preg_match('/\b(product|price|cost)\b/i', $qLower)) {
                        $keywords = trim(preg_replace('/\b(product|price|cost|how much|is|the)\b/i', '', $question));
                        if ($keywords === '') {
                            $productCards = $this->getBestSellingCards($detectedBrandId, 5);
                            if (!empty($productCards)) {
                                $arr = [];
                                foreach ($productCards as $card) {
                                    $arr[] = $card['name'] . ' (from ' . $card['price'] . ')';
                                }
                                $reply = 'Sure. Here are popular products you can check: ' . implode('; ', $arr) . '.';
                            } else {
                                $reply = 'Please share the product name and I will check the latest price for you.';
                            }
                        } else {
                            $productCards = $this->searchProductsByName($keywords, $detectedBrandId, 5);
                            if (!empty($productCards)) {
                                $arr = [];
                                foreach ($productCards as $card) {
                                    $arr[] = $card['name'] . ' (from ' . $card['price'] . ')';
                                }
                                $reply = 'Here are matching products: ' . implode('; ', $arr) . '.';
                            } else {
                                $reply = 'I could not find a matching product right now. Please try a more specific product name.';
                            }
                        }
                        $quickReplies = ['Track my order', 'Payment methods', 'Contact support'];
                    } elseif ($detectedBrandId > 0) {
                        $productCards = $this->getBestSellingCards($detectedBrandId, 6);
                        $reply = !empty($productCards)
                            ? ('Here are available ' . $detectedBrandName . ' products.')
                            : ('I found the brand ' . $detectedBrandName . ' but no active priced products are available right now.');
                        $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
                    } elseif (preg_match('/^[a-z0-9][a-z0-9\s\-\.\&]{2,}$/i', $question)) {
                        $productCards = $this->getTopicCards($this->buildSearchTokens($question), $detectedBrandId, 6);
                        if (empty($productCards)) {
                            $productCards = $this->searchProductsByName($question, $detectedBrandId, 6);
                        }
                        if (!empty($productCards)) {
                            $reply = 'Here are matching products for "' . $question . '".';
                            $quickReplies = ['Show lowest price', 'Best product', 'Track my order'];
                        } else {
                            $reply = 'I could not find a matching product right now. Please try a more specific product name.';
                        }
                    } else {
                        $reply = 'I can help with order tracking, payment methods, contact details, and product prices. Tell me what you need.';
                    }
                }
            }
        }
        }
        }
        }
        } catch (\Throwable $e) {
            Log::error('AiSupportController failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $reply = 'Support assistant is temporarily unavailable. Please try again in a moment.';
        }

        return response()->json([
            'status' => 'ok',
            'reply' => $reply,
            'quick_replies' => $quickReplies,
            'product_cards' => $productCards,
            'brand_cards' => $brandCards,
            'category_cards' => $categoryCards,
            'brand_view_all_url' => $brandViewAllUrl,
        ]);
    }

    private function defaultQuickReplies(): array
    {
        return [
            'What products match a minimalist style?',
            'Suggest items under PHP 5,000.',
            'What is best for office setup at home?',
            'What is the highest-rated product?',
            'What items are low in stock?',
            'Show me trending home decor.',
            'What if I received the wrong item?',
            'Do you accept GCash or online banking?',
            'How can I track my order?',
            'What happens if my item arrives damaged?',
            'What courier do you use?',
            'Can you recommend a sofa for small spaces?',
            'What are your best-selling living room products?',
            'Do you have items on sale right now?'
        ];
    }

    private function cleanInput(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return trim($value);
    }

    private function normalizeSimple(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = preg_replace('/[^a-z0-9\s]/', '', $value) ?? '';
        return trim($value);
    }

    private function normalizeSearchQuery(string $value): string
    {
        $base = trim($value);
        $clean = preg_replace('/\b(do you have|do you|have|show|find|give me|please|looking for|need|want|recommend|suggest|a|an|the)\b/i', ' ', $base) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $lower = strtolower($clean);

        $extras = [];
        if (preg_match('/\b(bed|bedroom|bed frame|bedframe)\b/i', $lower)) {
            $extras[] = 'bed';
        }
        if (preg_match('/\b(pillow|pillows|unan|onan)\b/i', $lower)) {
            $extras[] = 'pillow';
        }
        if (preg_match('/\b(sofa|sofas|bench|benches)\b/i', $lower)) {
            $extras[] = 'sofa';
            $extras[] = 'bench';
        }
        if (preg_match('/\b(mirror|mirrors|salamin|vanity mirror|wall mirror|mirror cabinet|full length mirror|full-length mirror)\b/i', $lower)) {
            $extras[] = 'mirror';
        }

        $cleanTokens = preg_split('/\s+/', trim(strtolower($clean))) ?: [];
        $tokens = array_filter($cleanTokens, fn ($token) => $token !== '');
        foreach ($extras as $extra) {
            $tokens[] = $extra;
        }
        $tokens = array_values(array_unique($tokens));
        $final = trim(implode(' ', $tokens));
        return $final !== '' ? $final : $base;
    }

    private function normalizeNameQuery(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === 'bed' || $normalized === 'bedroom') {
            return 'bed';
        }
        if (in_array($normalized, ['pillow', 'pillows', 'unan', 'onan'], true)) {
            return 'pillow';
        }
        if (in_array($normalized, ['sofa', 'sofas'], true)) {
            return 'sofa';
        }
        if (in_array($normalized, ['bench', 'benches'], true)) {
            return 'bench';
        }
        if (in_array($normalized, ['mirror', 'mirrors', 'salamin', 'vanity mirror', 'wall mirror', 'mirror cabinet', 'full length mirror', 'full-length mirror'], true)) {
            return 'mirror';
        }

        return $value;
    }

    private function getStrictNameKeywords(string $value): array
    {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['sofa', 'sofas', 'bench', 'benches'], true)) {
            return ['sofa', 'bench'];
        }
        if ($normalized === 'bed' || $normalized === 'bedroom') {
            return ['bed'];
        }
        if (in_array($normalized, ['pillow', 'pillows', 'unan', 'onan'], true)) {
            return ['pillow'];
        }
        if (in_array($normalized, ['mirror', 'mirrors', 'salamin', 'vanity mirror', 'wall mirror', 'mirror cabinet', 'full length mirror', 'full-length mirror'], true)) {
            return ['mirror'];
        }

        return $normalized !== '' ? [$normalized] : [];
    }

    private function detectStrictNameQuery(string $qLower, string $searchQuestion): string
    {
        if (preg_match('/\b(sofa|sofas|bench|benches)\b/i', $qLower)) {
            return 'sofa';
        }
        if (preg_match('/\b(pillow|pillows|unan|onan)\b/i', $qLower)) {
            return 'pillow';
        }
        if (preg_match('/\b(bed|bedroom|bed frame|bedframe)\b/i', $qLower)) {
            return 'bed';
        }
        if (preg_match('/\b(mirror|mirrors|salamin|vanity mirror|wall mirror|mirror cabinet|full length mirror|full-length mirror)\b/i', $qLower)) {
            return 'mirror';
        }

        return '';
    }

    private function mergeCardLists(array $base, array $add): array
    {
        $merged = [];
        foreach ($base as $card) {
            $key = $this->cardKey($card);
            $merged[$key] = $card;
        }
        foreach ($add as $card) {
            $key = $this->cardKey($card);
            if (!isset($merged[$key])) {
                $merged[$key] = $card;
            }
        }

        return array_values($merged);
    }

    private function cardKey(array $card): string
    {
        $url = (string) ($card['url'] ?? '');
        if ($url !== '') {
            return strtolower($url);
        }
        $name = (string) ($card['name'] ?? '');
        return strtolower($name);
    }

    private function extractBudget(string $input): float
    {
        $range = $this->extractBudgetRange($input);
        if ($range['max'] > 0) {
            return $range['max'];
        }

        return 0.0;
    }

    private function extractBudgetRange(string $input): array
    {
        $text = strtolower($input);
        $rangePattern = '/(?:php|₱)?\s*([0-9][0-9,\.]*\s*k?)\s*(?:-|–|to|up to|upto|below|under|less than)\s*(?:php|₱)?\s*([0-9][0-9,\.]*\s*k?)/i';
        if (preg_match($rangePattern, $text, $match)) {
            $min = $this->parseBudgetToken($match[1] ?? '');
            $max = $this->parseBudgetToken($match[2] ?? '');
            if ($max > 0 && $min > 0 && $min > $max) {
                [$min, $max] = [$max, $min];
            }
            return ['min' => $min > 0 ? $min : 1, 'max' => $max];
        }

        $singlePattern = '/(?:php|₱)?\s*([0-9][0-9,\.]*\s*k?)\b/i';
        if (preg_match($singlePattern, $text, $match)) {
            $max = $this->parseBudgetToken($match[1] ?? '');
            return ['min' => $max > 0 ? 1 : 0, 'max' => $max];
        }

        return ['min' => 0, 'max' => 0];
    }

    private function parseBudgetToken(string $value): float
    {
        $raw = strtolower(trim($value));
        $raw = str_replace([',', ' '], '', $raw);
        $isK = str_ends_with($raw, 'k');
        if ($isK) {
            $raw = substr($raw, 0, -1);
        }
        $num = (float) $raw;
        if ($num <= 0) {
            return 0.0;
        }
        return $isK ? $num * 1000 : $num;
    }

    private function hasBudgetIntent(string $qLower): bool
    {
        if (preg_match('/\b(budget|price range|price|cost|under|below|less than|up to|upto)\b/i', $qLower)) {
            return true;
        }
        if (preg_match('/\b[0-9][0-9,\.]*\s*k\b/i', $qLower)) {
            return true;
        }
        if (preg_match('/(?:php|₱)?\s*[0-9][0-9,\.]*\b/i', $qLower)) {
            return true;
        }

        return false;
    }

    private function handleBudgetIntent(
        string $question,
        string $qLower,
        string $searchQuestion,
        int $detectedBrandId
    ): array {
        $productCards = [];
        $categoryCards = [];
        $quickReplies = [];
        $reply = '';

        $range = $this->extractBudgetRange($qLower);
        $min = (float) ($range['min'] ?? 0);
        $max = (float) ($range['max'] ?? 0);
        $hasBudget = $max > 0;

        $categoryCards = $this->searchCategories($searchQuestion, 3);
        $hasCategory = !empty($categoryCards);

        if (!$hasBudget) {
            $reply = 'Sure! What budget range are you aiming for? You can say 2k, 2000, or 2000-5000.';
            $quickReplies = ['Under PHP 2,000', 'PHP 2,000 - 5,000', 'PHP 5,000 - 10,000', 'No budget yet'];
            return [
                'reply' => $reply,
                'quickReplies' => $quickReplies,
                'productCards' => $productCards,
                'categoryCards' => $categoryCards,
            ];
        }

        if (!$hasCategory) {
            $reply = 'Got it. What type of product do you want within that budget? You can pick a category.';
            $quickReplies = ['Home living', 'Appliances', 'Bedroom', 'Office setup', 'Outdoor'];
            return [
                'reply' => $reply,
                'quickReplies' => $quickReplies,
                'productCards' => $productCards,
                'categoryCards' => $categoryCards,
            ];
        }

        if ($min <= 0) {
            $min = 1;
        }
        $terms = $this->getSearchTermsFromQuery($qLower, $searchQuestion);
        if (empty($terms) && $hasCategory) {
            $categoryName = (string) ($categoryCards[0]['name'] ?? '');
            $terms = $this->buildSearchTokens($categoryName);
        }
        $productCards = !empty($terms)
            ? $this->getPriceRangeCardsWithTerms($terms, $min, $max, $detectedBrandId, 10)
            : $this->getPriceRangeCards($min, $max, $detectedBrandId, 10);
        $reply = !empty($productCards)
            ? 'Here are products that match your budget.'
            : 'I could not find products in that price range. Try a higher budget or another category.';
        $quickReplies = ['Show lowest price', 'Best product', 'Track my order', 'Change category'];

        return [
            'reply' => $reply,
            'quickReplies' => $quickReplies,
            'productCards' => $productCards,
            'categoryCards' => $categoryCards,
        ];
    }

    private function handleGeneralRecommendationIntent(int $detectedBrandId, string $detectedBrandName): array
    {
        $productCards = [];
        $categoryCards = [];
        $quickReplies = [];

        $prompts = [
            'I can give you personalized recommendations 😊 What product are you looking for and how will you use it?',
            'Let’s find the best match for you 👍 What item do you need and any preferences?',
            'I will suggest top options based on your needs—what are you shopping for today?',
            'Tell me a bit about what you need, and I will recommend the best options.',
            'Happy to help you choose. What product do you need and how will you use it?',
            'Great! What item are you looking for and any must-have features?',
            'I can help you decide. What are you shopping for and what matters most to you?',
            'Tell me the product you need and your preferences, and I will suggest the best picks.',
        ];

        if ($detectedBrandId > 0 && $detectedBrandName !== '') {
            $prompts[] = 'Sure! Are you looking for a specific ' . $detectedBrandName . ' item? Tell me how you will use it.';
        }

        $reply = $prompts[array_rand($prompts)];
        $quickReplies = [
            'Home living',
            'Appliances',
            'Bedroom',
            'Office setup',
            'Outdoor',
            'Budget-friendly',
            'Premium',
        ];

        return [
            'reply' => $reply,
            'quickReplies' => $quickReplies,
            'productCards' => $productCards,
            'categoryCards' => $categoryCards,
        ];
    }

    private function isDiningTableIntent(string $qLower): bool
    {
        if (!preg_match('/\b(dining table|dining set|mesa|mesa kainan|mesa sa kainan|kainan)\b/i', $qLower)) {
            return false;
        }

        return true;
    }

    private function handleDiningTableIntent(int $detectedBrandId, string $question): array
    {
        $productCards = [];
        $quickReplies = [];

        $seatRange = $this->extractSeatRange($question);
        $seatText = '';
        if ($seatRange['min'] > 0 && $seatRange['max'] > 0) {
            $seatText = $seatRange['min'] . '–' . $seatRange['max'] . ' people';
        } elseif ($seatRange['min'] > 0) {
            $seatText = $seatRange['min'] . ' people';
        }

        $seatPrompt = $seatText !== '' ? ('Great choice for ' . $seatText . '. ') : '';
        $replyOptions = [
            $seatPrompt . 'Here are dining tables that fit your seating needs. Would you like a classic wood style or a modern design?',
            $seatPrompt . 'Here are good options for your seating range. Do you prefer wood, glass, or metal?',
            $seatPrompt . 'Here are dining tables ideal for your group size. Any preferred style or budget?',
            'Got it. Here are dining tables that match your seating size. Do you want a compact or standard size?',
        ];

        $productCards = $this->getTopicCards(
            ['dining table', 'dining set', 'table'],
            $detectedBrandId,
            10
        );

        if (empty($productCards)) {
            $replyOptions[] = 'I could not find dining table results right now, but I can show tables if you tell me your budget and style.';
        }

        $reply = $replyOptions[array_rand($replyOptions)];
        $quickReplies = [
            'Wood style',
            'Modern style',
            'Under PHP 10,000',
            'For 2–4 people',
            'For 6–8 people',
        ];

        return [
            'reply' => $reply,
            'quickReplies' => $quickReplies,
            'productCards' => $productCards,
            'categoryCards' => [],
        ];
    }

    private function extractSeatRange(string $question): array
    {
        $text = strtolower($question);
        $map = [
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
        ];
        foreach ($map as $word => $num) {
            $text = preg_replace('/\b' . $word . '\b/i', (string) $num, $text) ?? $text;
        }

        if (preg_match('/\b([0-9]{1,2})\s*(?:-|–|to)\s*([0-9]{1,2})\s*(?:people|persons|pax|seater|seats|tao)?\b/i', $text, $match)) {
            $min = (int) $match[1];
            $max = (int) $match[2];
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }
            return ['min' => $min, 'max' => $max];
        }

        if (preg_match('/\b(for|para sa|para)\s*([0-9]{1,2})\s*(?:people|persons|pax|seater|seats|tao)?\b/i', $text, $match)) {
            $min = (int) $match[2];
            return ['min' => $min, 'max' => $min];
        }

        return ['min' => 0, 'max' => 0];
    }

    private function handleBestProductIntent(
        string $question,
        string $qLower,
        string $searchQuestion,
        int $detectedBrandId,
        string $detectedBrandName
    ): array {
        $productCards = [];
        $categoryCards = [];
        $quickReplies = [];
        $reply = '';

        $budget = $this->extractBudget($qLower);
        $hasBudget = $budget > 0;
        $categoryCards = $this->searchCategories($searchQuestion, 3);
        $hasCategory = !empty($categoryCards);

        if (!$hasCategory && $detectedBrandId <= 0) {
            $categoryPrompts = [
                'Sure! I can help with that. What type of product are you looking for? For example, home living, appliances, or something else?',
                'Absolutely. What kind of product are you shopping for today? Home living, appliances, bedroom, or another category?',
                'Happy to help. Which category are you interested in? Home living, appliances, office, or something else?',
                'Got it. What product category should I focus on? Home living, appliances, bedroom, or outdoor?',
                'I can recommend the best items. Which area are you shopping for: home living, appliances, office, or another category?',
                'Sure thing. Tell me the category you want: home living, appliances, bedroom, or any other.',
                'Let’s narrow it down. Are you looking for home living, appliances, bedroom, or office items?',
                'Great! Which category fits what you need: home living, appliances, bedroom, or something else?',
            ];
            $reply = $categoryPrompts[array_rand($categoryPrompts)];
            $quickReplies = ['Home living', 'Appliances', 'Bedroom', 'Office setup', 'Outdoor'];
            $categoryCards = [];
            return [
                'reply' => $reply,
                'quickReplies' => $quickReplies,
                'productCards' => $productCards,
                'categoryCards' => $categoryCards,
            ];
        }

        if (!$hasBudget) {
            $topic = '';
            if ($detectedBrandId > 0) {
                $topic = $detectedBrandName;
            } elseif ($hasCategory) {
                $topic = (string) ($categoryCards[0]['name'] ?? '');
            }
            $topicText = $topic !== '' ? ('Great! What kind of ' . $topic . ' do you need? ') : 'Great! What kind of product do you need? ';
            $budgetPrompts = [
                $topicText . 'And do you have a budget in mind?',
                $topicText . 'What budget range should I target?',
                $topicText . 'Do you have a budget or price range?',
                $topicText . 'What price range works for you?',
                $topicText . 'Any budget limit you want me to follow?',
                $topicText . 'Do you want budget-friendly or premium options?',
                $topicText . 'Rough budget range?',
                $topicText . 'What price range should I use?',
            ];
            $reply = $budgetPrompts[array_rand($budgetPrompts)];
            $quickReplies = ['Under PHP 5,000', 'PHP 5,000 - 10,000', 'PHP 10,000+', 'No budget yet', 'Budget-friendly', 'Premium'];
            return [
                'reply' => $reply,
                'quickReplies' => $quickReplies,
                'productCards' => $productCards,
                'categoryCards' => $categoryCards,
            ];
        }

        if ($detectedBrandId > 0) {
            $productCards = $this->getTopRatedCards($detectedBrandId, 5);
        }

        if (empty($productCards) && $hasCategory) {
            $tokens = $this->getSearchTermsFromQuery($qLower, $searchQuestion);
            if (empty($tokens)) {
                $categoryName = (string) ($categoryCards[0]['name'] ?? '');
                $tokens = $this->buildSearchTokens($categoryName);
            }
            $productCards = $this->getTopicCards($tokens, $detectedBrandId, 8);
        }

        if (empty($productCards)) {
            $productCards = $this->getTopRatedCards(0, 5);
        }

        if (!empty($productCards)) {
            $finalReplies = [
                'Here are some top picks based on your request.',
                'Here are the best options that match what you asked for.',
                'These are solid recommendations based on your needs.',
                'Here are top choices you might like.',
                'I found some great picks for you.',
                'Here are the best matches I can recommend.',
                'These are top-rated options that fit your request.',
                'Here are strong recommendations for you.',
            ];
            $reply = $finalReplies[array_rand($finalReplies)];
        } else {
            $reply = 'I can help you choose. Tell me the product type and budget, and I will recommend options.';
        }
        $quickReplies = ['Show lowest price', 'Best product', 'Track my order', 'Change category', 'Set a budget'];

        return [
            'reply' => $reply,
            'quickReplies' => $quickReplies,
            'productCards' => $productCards,
            'categoryCards' => $categoryCards,
        ];
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'product';
    }

    private function frontendBaseUrl(): string
    {
        $base = rtrim((string) env('NEXT_PUBLIC_APP_URL', ''), '/');
        if ($base === '') {
            $base = rtrim((string) env('FRONTEND_URL', ''), '/');
        }
        if ($base === '') {
            $base = rtrim((string) env('APP_URL', ''), '/');
        }
        return $base;
    }

    private function backendBaseUrl(): string
    {
        $base = rtrim((string) env('APP_URL', ''), '/');
        return $base !== '' ? $base : $this->frontendBaseUrl();
    }

    private function mapProductCards($rows): array
    {
        $cards = [];
        $frontendBase = $this->frontendBaseUrl();
        $backendBase = $this->backendBaseUrl();
        $fallbackImage = ($frontendBase !== '' ? $frontendBase : '') . '/Images/HeroSection/chairs_stools.jpg';

        foreach ($rows as $row) {
            $name = trim((string) ($row->pd_name ?? ''));
            if ($name !== '') {
                $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
                $name = str_replace(['&nbsp;', '&amp;nbsp;', '&quot;', '&amp;quot;'], ' ', $name);
                $name = str_replace(["\xc2\xa0", "\xa0"], ' ', $name);
                $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
            }

            $id = (int) ($row->pd_id ?? 0);
            $price = (float) ($row->min_price ?? 0);
            if ($id <= 0 || $name === '' || $price <= 0) {
                continue;
            }

            $filename = trim((string) ($row->pp_filename ?? ''));
            $image = $fallbackImage;
            if ($filename !== '') {
                if (preg_match('#^https?://#i', $filename)) {
                    $image = $filename;
                } else {
                    $image = ($backendBase !== '' ? $backendBase : '') . '/product_img/' . rawurlencode($filename);
                }
            }

            $slug = $this->slugify($name);
            $url = ($frontendBase !== '' ? $frontendBase : '') . '/product/' . $slug . '-i' . $id;

            $descRaw = trim((string) ($row->pd_description ?? ''));
            $descText = '';
            if ($descRaw !== '') {
                $decoded = html_entity_decode($descRaw, ENT_QUOTES, 'UTF-8');
                $decoded = str_replace(['&nbsp;', '&amp;nbsp;'], ' ', $decoded);
                $decoded = str_replace(["\xc2\xa0", "\xa0"], ' ', $decoded);
                $descText = trim(preg_replace('/\s+/', ' ', strip_tags($decoded)) ?? '');
                if (strlen($descText) > 140) {
                    $descText = substr($descText, 0, 137) . '...';
                }
            }

            $priceDecimals = (abs($price - floor($price)) < 0.00001) ? 0 : 2;
            $cards[] = [
                'name' => $name,
                'price' => 'PHP ' . number_format($price, $priceDecimals),
                'description' => $descText,
                'image' => $image,
                'url' => $url,
            ];
        }

        return $cards;
    }

    private function searchCategories(string $search, int $limit = 6): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $tokens = $this->buildSearchTokens($search);
        $like = '%' . $search . '%';

        $query = DB::table('tbl_category as c')
            ->leftJoin('tbl_product as p', function ($join) {
                $join->on('p.pd_catid', '=', 'c.cat_id')
                    ->whereIn('p.pd_status', [1, 2]);
            })
            ->select([
                'c.cat_id',
                'c.cat_name',
                'c.cat_url',
                DB::raw('COUNT(DISTINCT p.pd_id) as product_count'),
            ])
            ->where(function ($builder) use ($like, $tokens) {
                $builder
                    ->where('c.cat_name', 'ilike', $like)
                    ->orWhere('c.cat_url', 'ilike', $like);

                foreach ($tokens as $token) {
                    $tokenLike = '%' . $token . '%';
                    $builder
                        ->orWhere('c.cat_name', 'ilike', $tokenLike)
                        ->orWhere('c.cat_url', 'ilike', $tokenLike);
                }
            })
            ->groupBy('c.cat_id', 'c.cat_name', 'c.cat_url')
            ->orderByDesc(DB::raw('COUNT(DISTINCT p.pd_id)'))
            ->orderBy('c.cat_name')
            ->limit(max(1, min($limit, 10)));

        $rows = $query->get();
        $frontendBase = $this->frontendBaseUrl();
        $cards = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row->cat_name ?? ''));
            if ($name === '') {
                continue;
            }

            $slug = trim((string) ($row->cat_url ?? ''));
            if ($slug === '') {
                $slug = Str::slug($name);
            }

            if ($slug === '') {
                continue;
            }

            $cards[] = [
                'name' => $name,
                'count' => (int) ($row->product_count ?? 0),
                'url' => ($frontendBase !== '' ? $frontendBase : '') . '/category/' . rawurlencode($slug),
            ];
        }

        return $cards;
    }

    private function productBaseQuery(int $brandId = 0, bool $withCategory = false)
    {
        $photoSub = DB::table('tbl_product_photo')
            ->select('pp_pdid', DB::raw('MIN(pp_id) as min_pp_id'))
            ->groupBy('pp_pdid');

        $query = DB::table('tbl_product as p')
            ->join('tbl_product_variant as v', 'v.pv_pdid', '=', 'p.pd_id')
            ->leftJoinSub($photoSub, 'fp', function ($join) {
                $join->on('fp.pp_pdid', '=', 'p.pd_id');
            })
            ->leftJoin('tbl_product_photo as pp', 'pp.pp_id', '=', 'fp.min_pp_id')
            ->where('p.pd_status', 1)
            ->where('v.pv_price_srp', '>', 0)
            ->whereRaw("LOWER(TRIM(p.pd_name)) !~ '^(test|sample|demo)[0-9 _-]*$'");

        if ($brandId > 0) {
            $query->where('p.pd_brand_type', $brandId);
        }

        if ($withCategory) {
            $query->leftJoin('tbl_category as c', 'c.cat_id', '=', 'p.pd_catid')
                ->leftJoin('tbl_categorysub as cs', 'cs.subcat_id', '=', 'p.pd_catsubid')
                ->leftJoin('tbl_categoryitem as i', 'i.item_id', '=', 'p.pd_catsubid2');
        }

        return $query;
    }

    private function priceExpression(bool $forMember): string
    {
        if ($forMember) {
            return 'MIN(CASE WHEN v.pv_price_member IS NOT NULL AND v.pv_price_member > 0 THEN v.pv_price_member ELSE v.pv_price_srp END)';
        }

        return 'MIN(v.pv_price_srp)';
    }

    private function selectProductFields($query, bool $forMember)
    {
        $priceExpr = $this->priceExpression($forMember);
        return $query->selectRaw('p.pd_id, p.pd_name, ' . $priceExpr . ' AS min_price, MAX(p.pd_description) AS pd_description, MAX(pp.pp_filename) AS pp_filename')
            ->groupBy('p.pd_id', 'p.pd_name');
    }

    private function getPriceSortedCards(int $brandId, string $order, int $limit): array
    {
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        $rows = $query
            ->orderByRaw('min_price ' . $order)
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function getPriceRangeCards(float $minBudget, float $maxBudget, int $brandId, int $limit): array
    {
        $min = max(0, $minBudget);
        $max = max($min, $maxBudget);
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        $rows = $query
            ->havingRaw($this->priceExpression($this->isMember) . ' >= ? AND ' . $this->priceExpression($this->isMember) . ' <= ?', [$min, $max])
            ->orderByRaw('min_price ASC')
            ->orderByDesc('p.pd_sales')
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function getPriceRangeCardsWithTerms(array $terms, float $minBudget, float $maxBudget, int $brandId, int $limit): array
    {
        $min = max(0, $minBudget);
        $max = max($min, $maxBudget);
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $query = $this->productBaseQuery($brandId, true);
        $query = $this->selectProductFields($query, $this->isMember);
        $query->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $kw = trim((string) $term);
                if ($kw === '') {
                    continue;
                }
                $like = '%' . strtolower($kw) . '%';
                $q->orWhereRaw('LOWER(p.pd_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(p.pd_description, \'\')) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(c.cat_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(cs.subcat_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(i.item_name) LIKE ?', [$like]);
            }
        });

        $rows = $query
            ->havingRaw($this->priceExpression($this->isMember) . ' >= ? AND ' . $this->priceExpression($this->isMember) . ' <= ?', [$min, $max])
            ->orderByRaw('min_price ASC')
            ->orderByDesc('p.pd_sales')
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function getTopRatedCards(int $brandId, int $limit): array
    {
        try {
            $reviews = DB::table('tbl_product_reviews')
                ->select('pr_product_id', DB::raw('AVG(pr_rating) AS avg_rating'), DB::raw('COUNT(*) AS review_count'))
                ->where('pr_status', 1)
                ->groupBy('pr_product_id');

            $query = $this->productBaseQuery($brandId)
                ->joinSub($reviews, 'r', function ($join) {
                    $join->on('r.pr_product_id', '=', 'p.pd_id');
                });

            $rows = $this->selectProductFields($query, $this->isMember)
                ->addSelect(DB::raw('r.avg_rating'), DB::raw('r.review_count'))
                ->orderByDesc('r.avg_rating')
                ->orderByDesc('r.review_count')
                ->orderByDesc('p.pd_sales')
                ->orderByDesc('p.pd_id')
                ->limit($limit > 0 ? $limit : 5)
                ->get();

            return $this->mapProductCards($rows);
        } catch (\Throwable) {
            return [];
        }
    }

    private function getBestSellingCards(int $brandId, int $limit): array
    {
        $rows = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember)
            ->orderByDesc('p.pd_sales')
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function getTopicCards(array $terms, int $brandId, int $limit): array
    {
        $whereParts = [];
        $scoreParts = [];
        foreach ($terms as $term) {
            $kw = trim((string) $term);
            if ($kw === '') {
                continue;
            }
            $whereParts[] = "LOWER(p.pd_name) LIKE ?";
            $whereParts[] = "LOWER(c.cat_name) LIKE ?";
            $whereParts[] = "LOWER(cs.subcat_name) LIKE ?";
            $whereParts[] = "LOWER(i.item_name) LIKE ?";

            $scoreParts[] = "MAX(CASE WHEN LOWER(p.pd_name) LIKE ? THEN 6 ELSE 0 END)";
            $scoreParts[] = "MAX(CASE WHEN LOWER(i.item_name) LIKE ? THEN 4 ELSE 0 END)";
            $scoreParts[] = "MAX(CASE WHEN LOWER(cs.subcat_name) LIKE ? THEN 3 ELSE 0 END)";
            $scoreParts[] = "MAX(CASE WHEN LOWER(c.cat_name) LIKE ? THEN 2 ELSE 0 END)";
        }

        if (empty($whereParts)) {
            return [];
        }

        $bindings = [];
        $scoreBindings = [];
        foreach ($terms as $term) {
            $kw = '%' . strtolower(trim((string) $term)) . '%';
            $bindings[] = $kw;
            $bindings[] = $kw;
            $bindings[] = $kw;
            $bindings[] = $kw;

            $scoreBindings[] = $kw;
            $scoreBindings[] = $kw;
            $scoreBindings[] = $kw;
            $scoreBindings[] = $kw;
        }

        $query = $this->productBaseQuery($brandId, true);
        $query = $this->selectProductFields($query, $this->isMember);

        $scoreSql = '(' . implode(' + ', $scoreParts) . ') AS match_score';
        $query->selectRaw($scoreSql, $scoreBindings);

        $query->whereRaw('(' . implode(' OR ', $whereParts) . ')', $bindings);
        $query->havingRaw($this->priceExpression($this->isMember) . ' > 0');

        $rows = $query
            ->orderByDesc('match_score')
            ->orderByDesc('p.pd_sales')
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 4)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function buildSearchTokens(string $text, int $minLen = 3): array
    {
        $clean = strtolower($text);
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $clean) ?? '';
        $clean = preg_replace('/\s+/', ' ', trim($clean)) ?? '';
        if ($clean === '') {
            return [];
        }
        $stop = ['the','and','for','with','from','this','that','your','you','show','need','want','find','give','me','please','item','items','product','products','price','cost','php','peso','pesos','best','seller','recommended','recommend','cheap','low','lowest','high','highest','under','over','below','above'];
        $parts = explode(' ', $clean);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || strlen($p) < $minLen || in_array($p, $stop, true)) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function getExactOrClosestProduct(string $rawQuery, int $brandId): array
    {
        $q = trim($rawQuery);
        if ($q === '') {
            return [];
        }

        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        $rows = $query
            ->whereRaw('LOWER(TRIM(p.pd_name)) = LOWER(TRIM(?))', [$q])
            ->limit(1)
            ->get();
        $cards = $this->mapProductCards($rows);
        if (!empty($cards)) {
            return $cards;
        }

        $tokens = $this->buildSearchTokens($q);
        if (empty($tokens)) {
            return [];
        }

        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        foreach ($tokens as $t) {
            $query->whereRaw('LOWER(p.pd_name) LIKE ?', ['%' . strtolower($t) . '%']);
        }

        $rows = $query
            ->orderByRaw('LOWER(p.pd_name) = LOWER(?) DESC', [$q])
            ->orderByDesc('p.pd_sales')
            ->orderByDesc('p.pd_id')
            ->limit(1)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function searchProductsByName(string $keywords, int $brandId, int $limit): array
    {
        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        $rows = $query
            ->where(function ($q) use ($keywords) {
                $like = '%' . strtolower($keywords) . '%';
                $q->whereRaw('LOWER(p.pd_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(p.pd_description, \'\')) LIKE ?', [$like]);
            })
            ->orderByRaw('LOWER(p.pd_name) = LOWER(?) DESC', [$keywords])
            ->orderByDesc('p.pd_sales')
            ->orderBy('min_price')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function searchProductsByNameOnly(string $keywords, int $brandId, int $limit): array
    {
        $query = $this->selectProductFields($this->productBaseQuery($brandId), $this->isMember);
        $like = '%' . strtolower($keywords) . '%';
        $rows = $query
            ->whereRaw('LOWER(p.pd_name) LIKE ?', [$like])
            ->orderByRaw('LOWER(p.pd_name) = LOWER(?) DESC', [$keywords])
            ->orderByDesc('p.pd_sales')
            ->orderBy('min_price')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function searchProductsByKeywords(string $keywords, int $brandId, int $limit): array
    {
        $tokens = $this->buildSearchTokens($keywords, 2);
        if (empty($tokens)) {
            return [];
        }

        $query = $this->productBaseQuery($brandId, true);
        $query = $this->selectProductFields($query, $this->isMember);

        $query->where(function ($q) use ($tokens) {
            foreach ($tokens as $t) {
                $like = '%' . strtolower($t) . '%';
                $q->orWhereRaw('LOWER(p.pd_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(p.pd_description, \'\')) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(c.cat_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(cs.subcat_name) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(i.item_name) LIKE ?', [$like]);
            }
        });

        $rows = $query
            ->orderByDesc('p.pd_sales')
            ->orderBy('min_price')
            ->limit($limit > 0 ? $limit : 10)
            ->get();

        return $this->mapProductCards($rows);
    }

    private function searchProductsByNameNoPrice(string $keywords, int $limit): array
    {
        $photoSub = DB::table('tbl_product_photo')
            ->select('pp_pdid', DB::raw('MIN(pp_id) as min_pp_id'))
            ->groupBy('pp_pdid');

        $rows = DB::table('tbl_product as p')
            ->leftJoinSub($photoSub, 'fp', function ($join) {
                $join->on('fp.pp_pdid', '=', 'p.pd_id');
            })
            ->leftJoin('tbl_product_photo as pp', 'pp.pp_id', '=', 'fp.min_pp_id')
            ->where('p.pd_status', 1)
            ->whereRaw('LOWER(TRIM(p.pd_name)) !~ \'^(test|sample|demo)[0-9 _-]*$\'')
            ->whereRaw('LOWER(p.pd_name) LIKE ?', ['%' . strtolower($keywords) . '%'])
            ->selectRaw('p.pd_id, p.pd_name, MAX(p.pd_price_member) AS member_price, MAX(p.pd_price_srp) AS srp_price, MAX(p.pd_price_dp) AS dp_price, MAX(p.pd_description) AS pd_description, MAX(pp.pp_filename) AS pp_filename')
            ->groupBy('p.pd_id', 'p.pd_name')
            ->orderByRaw('LOWER(p.pd_name) = LOWER(?) DESC', [$keywords])
            ->orderByDesc('p.pd_id')
            ->limit($limit > 0 ? $limit : 5)
            ->get();

        return $this->mapProductCardsNoPrice($rows);
    }

    private function mapProductCardsNoPrice($rows): array
    {
        $cards = [];
        $frontendBase = $this->frontendBaseUrl();
        $backendBase = $this->backendBaseUrl();
        $fallbackImage = ($frontendBase !== '' ? $frontendBase : '') . '/Images/HeroSection/chairs_stools.jpg';

        foreach ($rows as $row) {
            $name = trim((string) ($row->pd_name ?? ''));
            if ($name !== '') {
                $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
                $name = str_replace(['&nbsp;', '&amp;nbsp;', '&quot;', '&amp;quot;'], ' ', $name);
                $name = str_replace(["\xc2\xa0", "\xa0"], ' ', $name);
                $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
            }

            $id = (int) ($row->pd_id ?? 0);
            if ($id <= 0 || $name === '') {
                continue;
            }

            $filename = trim((string) ($row->pp_filename ?? ''));
            $image = $fallbackImage;
            if ($filename !== '') {
                if (preg_match('#^https?://#i', $filename)) {
                    $image = $filename;
                } else {
                    $image = ($backendBase !== '' ? $backendBase : '') . '/product_img/' . rawurlencode($filename);
                }
            }

            $slug = $this->slugify($name);
            $url = ($frontendBase !== '' ? $frontendBase : '') . '/product/' . $slug . '-i' . $id;

            $descRaw = trim((string) ($row->pd_description ?? ''));
            $descText = '';
            if ($descRaw !== '') {
                $decoded = html_entity_decode($descRaw, ENT_QUOTES, 'UTF-8');
                $decoded = str_replace(['&nbsp;', '&amp;nbsp;'], ' ', $decoded);
                $decoded = str_replace(["\xc2\xa0", "\xa0"], ' ', $decoded);
                $descText = trim(preg_replace('/\s+/', ' ', strip_tags($decoded)) ?? '');
                if (strlen($descText) > 140) {
                    $descText = substr($descText, 0, 137) . '...';
                }
            }

            $memberPrice = (float) ($row->member_price ?? 0);
            $srpPrice = (float) ($row->srp_price ?? 0);
            $dpPrice = (float) ($row->dp_price ?? 0);
            $priceNum = 0.0;
            if ($this->isMember) {
                $priceNum = $memberPrice > 0 ? $memberPrice : ($srpPrice > 0 ? $srpPrice : $dpPrice);
            } else {
                $priceNum = $srpPrice > 0 ? $srpPrice : ($dpPrice > 0 ? $dpPrice : $memberPrice);
            }
            if ($priceNum < 0) {
                $priceNum = 0;
            }
            $priceDecimals = (abs($priceNum - floor($priceNum)) < 0.00001) ? 0 : 2;
            $priceText = 'PHP ' . number_format($priceNum, $priceDecimals);

            $cards[] = [
                'name' => $name,
                'price' => $priceText,
                'description' => $descText,
                'image' => $image,
                'url' => $url,
            ];
        }

        return $cards;
    }

    private function detectBrand(string $qLower): array
    {
        $detectedId = 0;
        $detectedName = '';
        $bestLen = 0;

        $brands = DB::table('tbl_product_brand')->select('pb_id', 'pb_name')->get();
        foreach ($brands as $br) {
            $brandId = (int) ($br->pb_id ?? 0);
            $brandName = trim((string) ($br->pb_name ?? ''));
            if ($brandId <= 0 || $brandName === '') {
                continue;
            }
            $needle = strtolower(html_entity_decode($brandName, ENT_QUOTES, 'UTF-8'));
            if ($needle !== '' && str_contains($qLower, $needle)) {
                $len = strlen($needle);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $detectedId = $brandId;
                    $detectedName = $needle;
                }
            }
        }

        return [
            'id' => $detectedId,
            'name' => $detectedName !== '' ? Str::title($detectedName) : ''
        ];
    }

    private function getActiveBrandCount(): int
    {
        $row = DB::table('tbl_product as p')
            ->join('tbl_product_brand as b', 'b.pb_id', '=', 'p.pd_brand_type')
            ->where('p.pd_status', 1)
            ->where('p.pd_brand_type', '>', 0)
            ->selectRaw('COUNT(DISTINCT p.pd_brand_type) AS brand_count')
            ->first();

        return max(0, (int) ($row->brand_count ?? 0));
    }

    private function getTopActiveBrands(int $limit = 10): array
    {
        $rows = DB::table('tbl_product as p')
            ->join('tbl_product_brand as b', 'b.pb_id', '=', 'p.pd_brand_type')
            ->where('p.pd_status', 1)
            ->where('p.pd_brand_type', '>', 0)
            ->whereRaw("TRIM(COALESCE(b.pb_name,'')) <> ''")
            ->groupBy('b.pb_id', 'b.pb_name')
            ->selectRaw('b.pb_id, b.pb_name, COUNT(*) AS product_count')
            ->orderByDesc('product_count')
            ->orderBy('b.pb_name')
            ->limit($limit > 0 ? $limit : 10)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->pb_name ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'id' => (int) ($row->pb_id ?? 0),
                'name' => $name,
                'product_count' => (int) ($row->product_count ?? 0),
            ];
        }

        return $out;
    }

    private function getPaymentMethods(): array
    {
        try {
            $rows = DB::table('tbl_payment')
                ->select('p_name')
                ->where(function ($q) {
                    $q->where('p_status', 0)->orWhereNull('p_status');
                })
                ->orderBy('p_name')
                ->get();

            $methods = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row->p_name ?? ''));
                if ($name !== '') {
                    $methods[] = $name;
                }
            }

            return $methods;
        } catch (\Throwable $e) {
            Log::warning('AiSupportController payment methods fallback', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'GCash',
                'Maya',
                'Credit/Debit Card',
                'Bank Transfer',
            ];
        }
    }

    private function getSupportDetails(): array
    {
        $row = DB::table('tbl_control_panel')->select('OFFICE_EMAIL', 'OFFICE_NUMBER')->first();
        return [
            'email' => trim((string) ($row->OFFICE_EMAIL ?? '')),
            'phone' => trim((string) ($row->OFFICE_NUMBER ?? '')),
        ];
    }

    private function handleOrderTracking(string $question, bool $isMember, int $memberId): array
    {
        $orderNo = '';
        if (preg_match('/\b\d{6,}-\d{6,}\b/', $question, $m)) {
            $orderNo = trim((string) $m[0]);
        }

        if ($orderNo !== '') {
            if ($isMember) {
                $row = DB::table('tbl_order')
                    ->select('od_orderno', 'od_status', 'od_claim_status', 'od_date')
                    ->where('od_user', $memberId)
                    ->where('od_orderno', $orderNo)
                    ->orderByDesc('od_id')
                    ->first();
                if ($row) {
                    $date = $row->od_date ? date('M j, Y h:i A', strtotime($row->od_date)) : '';
                    $reply = 'Order ' . $row->od_orderno . ' status: ' . $this->statusLabel((int) $row->od_status, (int) $row->od_claim_status) . ($date !== '' ? (' (' . $date . ').') : '.');
                } else {
                    $reply = 'I could not find that order number in your account. Please verify the number.';
                }
            } else {
                $guestTable = $this->findGuestTable();
                if ($guestTable === '') {
                    $reply = 'Guest tracking table is not available right now. Please try again later.';
                } else {
                    $row = DB::table($guestTable . ' as g')
                        ->leftJoin('tbl_order as o', 'o.od_orderno', '=', 'g.order_no')
                        ->select('g.order_no', 'g.tracking_number', 'o.od_status', 'o.od_claim_status')
                        ->where('g.order_no', $orderNo)
                        ->orderByDesc('g.go_id')
                        ->first();
                    if ($row) {
                        $track = trim((string) ($row->tracking_number ?? ''));
                        $reply = 'Order ' . $row->order_no . ' status: ' . $this->statusLabel((int) ($row->od_status ?? 0), (int) ($row->od_claim_status ?? 0)) . '. Tracking: ' . ($track !== '' ? $track : (string) $row->order_no) . '.';
                    } else {
                        $reply = 'I could not find that guest order number. You can also use the Guest Track Order page.';
                    }
                }
            }
        } elseif ($isMember) {
            $rows = DB::table('tbl_order')
                ->select('od_orderno', 'od_status', 'od_claim_status', 'od_date')
                ->where('od_user', $memberId)
                ->orderByDesc('od_id')
                ->limit(3)
                ->get();
            if ($rows->isNotEmpty()) {
                $items = [];
                foreach ($rows as $row) {
                    $date = $row->od_date ? date('M j, Y', strtotime($row->od_date)) : '';
                    $items[] = $row->od_orderno . ' (' . $this->statusLabel((int) $row->od_status, (int) $row->od_claim_status) . ($date !== '' ? ', ' . $date : '') . ')';
                }
                $reply = 'Your recent orders: ' . implode('; ', $items) . '.';
            } else {
                $reply = 'No recent orders found in your account.';
            }
        } else {
            $reply = 'Please share your order number (e.g., 2026020-639297319632) so I can check your status.';
        }

        return ['reply' => $reply];
    }

    private function findGuestTable(): string
    {
        $candidates = ['tbl_guest-order', 'tbl_guest_order'];
        foreach ($candidates as $tbl) {
            try {
                DB::select('SELECT 1 FROM "' . $tbl . '" LIMIT 1');
                return $tbl;
            } catch (\Throwable) {
                continue;
            }
        }
        return '';
    }

    private function statusLabel(int $odStatus, int $claimStatus): string
    {
        if ($odStatus === 0) {
            return 'To Pay';
        }
        if ($odStatus === 1 && $claimStatus === 0) {
            return 'Supplier to Pack';
        }
        if ($odStatus === 1 && $claimStatus === 1) {
            return 'Packed';
        }
        if ($odStatus === 1 && $claimStatus === 2) {
            return 'In Transit';
        }
        if ($odStatus === 1 && $claimStatus === 3) {
            return 'Delivered / Completed';
        }
        if ($odStatus === 1 && $claimStatus === 4) {
            return 'Cancelled';
        }
        if ($odStatus === 1 && $claimStatus === 5) {
            return 'Return / Refund';
        }
        return 'Processing';
    }

    private function extractTopicTerms(string $qLower): array
    {
        $topicTerms = [];
        if (preg_match('/\bappliances?\b/i', $qLower)) {
            $topicTerms[] = 'appliance';
        }
        if (preg_match('/\b(tv|television)\b/i', $qLower)) {
            $topicTerms[] = 'tv';
            $topicTerms[] = 'television';
        }
        if (preg_match('/\b(beedroom|bedroom)\b/i', $qLower)) {
            $topicTerms[] = 'bedroom';
        }
        if (preg_match('/\bbed\b/i', $qLower)) {
            $topicTerms[] = 'bed';
        }
        if (preg_match('/\bpillow\b/i', $qLower)) {
            $topicTerms[] = 'pillow';
        }
        if (preg_match('/\bsofas?\b/i', $qLower)) {
            $topicTerms[] = 'sofa';
        }
        if (preg_match('/\btabo\b/i', $qLower)) {
            $topicTerms[] = 'tabo';
        }
        if (preg_match('/\broom\b/i', $qLower)) {
            $topicTerms[] = 'room';
        }
        if (preg_match('/\bchairs?\b/i', $qLower)) {
            $topicTerms[] = 'chair';
        }
        if (preg_match('/\btables?\b/i', $qLower)) {
            $topicTerms[] = 'table';
        }
        if (preg_match('/\bcabinets?\b/i', $qLower)) {
            $topicTerms[] = 'cabinet';
        }
        if (preg_match('/\bstools?\b/i', $qLower)) {
            $topicTerms[] = 'stool';
        }
        if (preg_match('/\bfurniture\b/i', $qLower)) {
            $topicTerms[] = 'furniture';
        }

        return $topicTerms;
    }

    private function extractRoomTokens(string $qLower): array
    {
        $tokens = [];
        if (preg_match('/\b(living room|sala)\b/i', $qLower)) {
            $tokens[] = 'living room';
        }
        if (preg_match('/\b(dining room|dining|kainan)\b/i', $qLower)) {
            $tokens[] = 'dining room';
        }
        if (preg_match('/\b(bedroom|bed room|kwarto|silid)\b/i', $qLower)) {
            $tokens[] = 'bedroom';
        }
        if (preg_match('/\b(kitchen|kusina)\b/i', $qLower)) {
            $tokens[] = 'kitchen';
        }
        if (preg_match('/\b(bathroom|comfort room|cr|banyo)\b/i', $qLower)) {
            $tokens[] = 'bathroom';
        }
        if (preg_match('/\b(office|workspace|study|opisina)\b/i', $qLower)) {
            $tokens[] = 'office';
        }
        if (preg_match('/\b(outdoor|garden|patio|balcony|terrace|labas)\b/i', $qLower)) {
            $tokens[] = 'outdoor';
        }
        if (preg_match('/\b(kids room|children room|nursery|baby room|pang bata)\b/i', $qLower)) {
            $tokens[] = 'kids room';
        }

        return $tokens;
    }

    private function getSearchTermsFromQuery(string $qLower, string $searchQuestion): array
    {
        $terms = array_merge($this->extractTopicTerms($qLower), $this->extractRoomTokens($qLower));
        if (empty($terms)) {
            $terms = $this->buildSearchTokens($searchQuestion, 2);
        }
        $unique = [];
        foreach ($terms as $term) {
            $t = trim((string) $term);
            if ($t === '') {
                continue;
            }
            $unique[$t] = true;
        }

        return array_keys($unique);
    }

    private function faqMap(): array
    {
        return [
            'hi' => 'Kumusta! Nandito ako para tumulong sa products, shipping, payments, at orders.',
            'hello' => 'Kumusta! Nandito ako para tumulong sa products, shipping, payments, at orders.',
            'kamusta' => 'Kumusta! Nandito ako para tumulong sa products, shipping, payments, at orders.',
            'kumusta' => 'Kumusta! Nandito ako para tumulong sa products, shipping, payments, at orders.',
            'magandang umaga' => 'Magandang umaga! Paano kita matutulungan ngayon?',
            'magandang hapon' => 'Magandang hapon! Ano ang maitutulong ko sa iyo?',
            'magandang gabi' => 'Magandang gabi! Ano ang maitutulong ko sa iyo?',
            'magandang araw' => 'Magandang araw! Paano kita matutulungan?',
            'nandyan ka ba' => 'Oo, nandito ako at handang tumulong. Ano ang kailangan mo?',
            'available ka ba' => 'Oo, available ako ngayon. Sabihin mo lang ang tanong mo.',
            'gumagana ka ba' => 'Oo, gumagana ako at handang tumulong. Ano ang kailangan mo?',
            'ayos ka ba' => 'Ayos ako, salamat! Paano kita matutulungan?',
            'mabuti ka ba' => 'Mabuti ako, salamat! Paano kita matutulungan?',
            'kumusta paano kayo makakatulong sa akin' => 'Kumusta! Maaari kitang tulungan sa paghahanap ng produkto, pagtsek ng order, at iba pang tanong tungkol sa aming tindahan.',
            'ano ang ecommerce ninyo' => 'Ang aming website ay nagbebenta ng produkto online at maaari kang bumili kahit nasa bahay ka lang.',
            'paano magsign up o gumawa ng account' => 'I-click ang "Mag-sign Up" at punan ang form gamit ang iyong email at password.',
            'libre ba ang pag sign up' => 'Oo! Libre ang paggawa ng account.',
            'paano maglogin' => 'I-click ang "Mag-login" at ilagay ang iyong email at password.',
            'ano ang available ninyong produkto' => 'Maaari mong tingnan lahat ng produkto sa aming "Shop" o gamitin ang search bar.',
            'paano ko malalaman ang laki o sukat ng produkto' => 'Bawat produkto ay may detalye sa description kasama ang sukat o dimension.',
            'may available ba kayong color red o blue' => 'Oo, nakalista ang kulay sa product page. Piliin ang nais na kulay bago mag-add to cart.',
            'paano magadd sa cart' => 'I-click ang "Add to Cart" sa produkto, at makikita mo ito sa iyong shopping cart.',
            'paano ko matitiyak na available pa ang stock' => 'Nakalagay sa product page kung may stock o out of stock ang item.',
            'paano ako makakabili' => 'Piliin ang produkto -> Add to Cart -> Checkout -> Pumili ng payment method -> Confirm order.',
            'puwede bang magorder ng maraming produkto' => 'Oo, puwede mong i-add sa cart ang lahat ng nais mong bilhin bago mag-checkout.',
            'may discount o promo ba' => 'Oo! Tingnan ang aming "Promotions" section para sa kasalukuyang promo codes at discounts.',
            'paano gamitin ang promo code' => 'Ilagay ang promo code sa checkout page bago i-confirm ang order.',
            'maaari ba akong magpreorder ng produkto' => 'Oo, may mga produkto na available for pre-order. Nakalagay ang details sa product page.',
            'anong payment methods ang tinatanggap ninyo' => 'Tinatanggap namin ang credit/debit card, GCash, PayMaya, at cash on delivery (COD).',
            'paano gumamit ng gcash sa pagbabayad' => 'Piliin ang GCash bilang payment option sa checkout at sundan ang instructions.',
            'libre ba ang cod' => 'Depende sa produkto at location, may kaunting shipping fee para sa COD.',
            'secure ba ang pagbabayad online' => 'Oo, ligtas ang aming payment gateway at may SSL encryption.',
            'maaari ba akong magbayad sa installment' => 'Oo, available ang installment sa ilang payment partners.',
            'paano malalaman kung successful ang payment' => 'Makakatanggap ka ng confirmation email o notification mula sa amin.',
            'magkano ang shipping fee' => 'Depende sa weight, size, at destination ng produkto. Makikita ito sa checkout.',
            'gaano katagal bago madeliver ang order' => 'Standard shipping ay 3-7 araw, express ay 1-3 araw depende sa location.',
            'may tracking number ba' => 'Oo, ibibigay namin ang tracking number para masubaybayan ang delivery.',
            'puwede bang magchange ng delivery address pagkatapos magorder' => 'Depende, kontakin agad ang customer support para ma-update ang address.',
            'ano ang ginagawa kung nadelay ang delivery' => 'Makipag-ugnayan sa amin o sa courier para sa updates at assistance.',
            'puwede bang magschedule ng delivery' => 'Oo, may option sa checkout para sa preferred delivery date.',
            'puwede bang pickup sa store' => 'Depende sa produkto at branch. Tingnan ang checkout options.',
            'paano magreturn ng produkto' => 'Kontakin ang customer support at sundan ang return instructions.',
            'puwede ba ang exchange ng produkto' => 'Oo, puwede palitan basta within return/exchange policy period.',
            'gaano katagal bago marefund' => 'Karaniwan 3-7 business days matapos ma-approve ang return.',
            'libre ba ang return shipping' => 'Depende sa item at reason ng return, nakasaad sa return policy.',
            'ano ang dapat gawin kung may defective na produkto' => 'I-report agad sa customer support at maaari itong ipalit o i-refund.',
            'paano makipagugnayan sa customer support' => 'Pwede sa chat, email, o hotline number na nakalagay sa website.',
            'available ba kayo 247' => 'Oo, ang chatbot ay available 24/7. Para sa human agent, depende sa office hours.',
            'ano ang average response time' => 'Sa chatbot, instant; sa email o human agent, 1-24 hours.',
            'paano magfollow up sa previous order inquiry' => 'Ibigay ang order number sa chatbot o customer support para matulungan ka.',
            'puwede ba akong magrequest ng special packaging' => 'Oo, may option sa checkout o sa customer support request.',
            'puwede bang humiling ng invoice' => 'Oo, makakakuha ka ng e-invoice pagkatapos ng order confirmation.',
            'ano ang bestseller products ninyo' => 'Makikita sa "Best Sellers" section ng website.',
            'ano ang recommended gift for 40 age' => 'Depende sa interest nila, pero popular ang home decor, gadgets, at fashion accessories.',
            'may seasonal products ba kayo' => 'Oo, may mga produkto na seasonal o limited edition.',
            'puwede ba akong humingi ng product suggestion' => 'Oo, sabihin lang ang budget, interest, o occasion, at tutulungan ka ng AI.',
            'ano ang bagong products' => 'Makikita sa "New Arrivals" section ng website.',
            'paano kung hindi gumana ang website' => 'Subukang i-refresh o i-clear ang cache. Kung hindi pa rin, kontakin ang customer support.',
            'paano kung hindi gumana ang payment' => 'Subukang ibang payment method o kontakin ang support para sa assistance.',
            'puwede bang iupdate ang account info' => 'Oo, sa "My Account" section puwede mong i-update ang personal details.',
            'nakalimutan ko ang password paano ko ireset' => 'I-click ang "Forgot Password" at sundin ang instructions para gumawa ng bago.',
            'puwede bang icancel ang order' => 'Oo, basta hindi pa naipadala. Kontakin agad ang customer support.',
            'paano matitiyak na ligtas ang personal info ko' => 'Lahat ng data ay secured at encrypted, at hindi ibinabahagi sa third parties.',
            'how can you help me' => 'Hi! I can help you find products, check orders, and answer questions about our store.',
            'what is your ecommerce' => 'Our website sells products online, so you can shop from home anytime.',
            'how do i sign up or create an account' => 'Click "Sign Up" and fill out the form using your email and password.',
            'is sign up free' => 'Yes! Creating an account is free.',
            'how do i log in' => 'Click "Log in" and enter your email and password.',
            'what products are available' => 'You can browse all products in our "Shop" or use the search bar.',
            'how do i know the size or dimensions' => 'Each product includes size and dimension details in the description.',
            'do you have red or blue color' => 'Yes, available colors are listed on the product page. Select your preferred color before adding to cart.',
            'how do i add to cart' => 'Click "Add to Cart" on a product and it will appear in your shopping cart.',
            'how can i make sure it is in stock' => 'The product page shows whether an item is in stock or out of stock.',
            'how can i buy' => 'Choose a product -> Add to Cart -> Checkout -> Select payment method -> Confirm order.',
            'can i order multiple products' => 'Yes, you can add multiple items to your cart before checkout.',
            'do you have discounts or promos' => 'Yes! Check our "Promotions" section for current promo codes and discounts.',
            'how do i use a promo code' => 'Enter the promo code on the checkout page before confirming your order.',
            'can i preorder a product' => 'Yes, some products are available for pre-order. Details are on the product page.',
            'what payment methods do you accept' => 'We accept credit/debit cards, GCash, PayMaya, and cash on delivery (COD).',
            'how to pay with gcash' => 'Select GCash at checkout and follow the instructions.',
            'is cod free' => 'It depends on the product and location; COD may include a small shipping fee.',
            'is online payment secure' => 'Yes, our payment gateway is secure and uses SSL encryption.',
            'can i pay in installment' => 'Yes, installment is available through selected payment partners.',
            'how do i know if payment is successful' => 'You will receive a confirmation email or notification from us.',
            'how much is the shipping fee' => 'Shipping fee depends on weight, size, and destination. You can see it at checkout.',
            'how long is delivery' => 'Standard shipping is 3-7 days; express is 1-3 days depending on location.',
            'do i get a tracking number' => 'Yes, we will provide a tracking number so you can monitor delivery.',
            'can i change the delivery address after ordering' => 'It depends; please contact customer support immediately to update your address.',
            'what if delivery is delayed' => 'Contact us or the courier for updates and assistance.',
            'can i schedule delivery' => 'Yes, you can select a preferred delivery date at checkout if available.',
            'can i pick up in store' => 'It depends on the product and branch. Check the checkout options.',
            'how do i return a product' => 'Contact customer support and follow the return instructions.',
            'can i exchange a product' => 'Yes, exchanges are allowed within the return/exchange policy period.',
            'how long does refund take' => 'Usually 3-7 business days after return approval.',
            'is return shipping free' => 'It depends on the item and return reason; please check the return policy.',
            'what if the product is defective' => 'Report it to customer support right away for replacement or refund.',
            'how can i contact customer support' => 'You can reach us via chat, email, or the hotline listed on the website.',
            'are you available 247' => 'Yes, the chatbot is available 24/7. Human agents are available during office hours.',
            'what is the average response time' => 'Chatbot: instant. Email or human agent: 1-24 hours.',
            'how do i follow up on an order inquiry' => 'Provide your order number to the chatbot or customer support.',
            'can i request special packaging' => 'Yes, you can request it at checkout or via customer support.',
            'can i request an invoice' => 'Yes, an e-invoice is available after order confirmation.',
            'what are your best seller products' => 'You can find them in the "Best Sellers" section of the website.',
            'what is a recommended gift for 40 age' => 'It depends on their interests, but home decor, gadgets, and fashion accessories are popular.',
            'do you have seasonal products' => 'Yes, some products are seasonal or limited edition.',
            'can you suggest a product' => 'Yes, share your budget, interest, or occasion and the AI will help.',
            'what are new products' => 'Check the "New Arrivals" section of the website.',
            'what if the website is not working' => 'Try refreshing or clearing cache. If it still fails, contact customer support.',
            'what if payment is not working' => 'Try another payment method or contact support for assistance.',
            'can i update my account info' => 'Yes, you can update your personal details in "My Account".',
            'i forgot my password how do i reset' => 'Click "Forgot Password" and follow the instructions to reset.',
            'can i cancel my order' => 'Yes, as long as it has not been shipped. Contact customer support immediately.',
            'how do you keep my personal info safe' => 'All data is secured and encrypted, and not shared with third parties.'
        ];
    }

    private function tagalogIntentAliases(): array
    {
        return [
            '/nasaan na po ang order ko\??/i' => ' track my order order status ',
            '/kailan darating ang order ko\??/i' => ' delivery time shipping time eta ',
            '/puwede pong ma-?track ang order\??/i' => ' track my order order tracking ',
            '/ano ang tracking number ko\??/i' => ' tracking number order tracking ',
            '/delayed po ba ang delivery\??/i' => ' delayed order delivery status ',
            '/out for delivery na po ba\??/i' => ' out for delivery delivery status ',
            '/puwede pong palitan ang delivery address\??/i' => ' change delivery address shipping address ',
            '/paano mag return ng item\??/i' => ' how do i return return an item ',
            '/puwede po bang i-?refund\??/i' => ' refund refund process ',
            '/kailan marerefund ang payment\??/i' => ' refund time refund status ',
            '/defective po yung item.*ano gagawin\??/i' => ' defective damaged item return policy ',
            '/wrong item po ang nareceive ko|wrong item po ang nareceive ko/i' => ' wrong item incorrect item ',
            '/puwede pong replacement\??/i' => ' replacement exchange item ',
            '/nagbayad na ako pero hindi reflected/i' => ' payment not reflected payment failed ',
            '/payment failed.*ano gagawin\??/i' => ' payment failed declined card ',
            '/puwede ba ang gcash\/maya\??/i' => ' gcash maya paymaya payment methods ',
            '/cash on delivery ba ito\??/i' => ' cash on delivery cod ',
            '/paano mag-?request ng invoice\??/i' => ' invoice payment receipt ',
            '/hindi ko nareceive ang confirmation|hindi ko nareceive ang confirmation/i' => ' order confirmation email not received ',
            '/nakalimutan ko password ko\??/i' => ' forgot password reset password ',
            '/paano mag-?change password\??/i' => ' change password reset password ',
            '/hindi ako makalogin\??/i' => ' login problem login issue ',
            '/paano mag-?update ng profile\??/i' => ' update profile account settings ',
            '/puwede bang mag-?delete ng account\??/i' => ' delete account close account ',
            '/available po ba ang stock\??/i' => ' stock availability in stock ',
            '/original ba itong product\??/i' => ' original authentic ',
            '/puwede pong makita ang size chart\??/i' => ' size chart sizes ',
            '/ano ang warranty\??/i' => ' warranty guarantee ',
            '/may ibang kulay ba\??/i' => ' kulay available colors available variants ',
            '/paano po mag-?contact ng support\??/i' => ' contact support customer service ',
            '/puwede pong mag-?follow up\??/i' => ' follow-up inquiry contact support ',
            '/may live chat ba\??/i' => ' live chat support ',
            '/saan ko makikita ang ticket number\??/i' => ' ticket number support ',
            '/salamat po/i' => ' thank you ',
            '/mga mababang price na product\??/i' => ' lowest low price cheapest budget ',
            '/give lowest price product/i' => ' lowest low price cheapest budget ',
            '/mo ba itrack ang order ko/i' => ' track my order order tracking ',
            '/saan ko makikita ang order history ko\??/i' => ' order history my orders ',
            '/pwede bang i-?cancel ang order\??/i' => ' cancel my order cancel order ',
            '/na-?shipped na po ba\??/i' => ' shipping status delivery status order status ',
            '/bakit hindi pa nadedeliver\??/i' => ' delivery delay delayed order delivery time ',
            '/puwede bang palitan ang order details\??/i' => ' modify my order change my order edit order ',
            '/ano status ng order ko\??/i' => ' order status track my order ',
            '/wala pa akong order confirmation/i' => ' order confirmation email not received ',
            '/paano mag-?track ng order\??/i' => ' track my order order tracking ',
            '/ilang araw bago madeliver\??/i' => ' delivery time shipping time eta ',
            '/puwede bang i-?reschedule ang delivery\??/i' => ' delivery reschedule delivery time contact support ',
            '/paano mag-?request ng return\??/i' => ' return an item how do i return ',
            '/gaano katagal ang refund process\??/i' => ' refund time refund process ',
            '/hindi ko pa natatanggap ang refund\??/i' => ' refund status refund time ',
            '/pwede bang palitan ng ibang item\??/i' => ' exchange item replacement ',
            '/wrong size\/item ang nareceive ko|wrong size\/item ang nareceive ko/i' => ' wrong item incorrect item exchange item ',
            '/may return fee ba\??/i' => ' return shipping return fee return policy ',
            '/saan ibabalik ang item\??/i' => ' return process return policy ',
            '/paano mag-?file ng return request\??/i' => ' return request return an item ',
            '/kailangan ba ng packaging\??/i' => ' return policy packaging return request ',
            '/payment successful ba\??/i' => ' payment successful order confirmation ',
            '/hindi nag-?proceed ang payment\??/i' => ' payment failed payment declined ',
            '/puwede ba ang installment\??/i' => ' installment installments ',
            '/ano payment methods\??/i' => ' payment methods ',
            '/nagdouble charge ako/i' => ' double charge payment issue contact support ',
            '/wala akong payment confirmation/i' => ' payment confirmation email not received ',
            '/gcash\/maya payment issue/i' => ' gcash maya payment failed ',
            '/credit card declined/i' => ' declined card payment failed ',
            '/cash on delivery available ba\??/i' => ' cash on delivery cod available ',
            '/nakalimutan ko password\??/i' => ' forgot password reset password ',
            '/paano mag-?update ng email\??/i' => ' update profile account settings ',
            '/account verification issue/i' => ' account verification verify account ',
            '/hindi ko mareceive otp/i' => ' otp not received account verification ',
            '/email not recognized/i' => ' login problem email issue ',
            '/paano mag-?add ng address\??/i' => ' shipping address add address update profile ',
            '/account locked/i' => ' account locked login problem contact support ',
            '/paano mag-?care ng product\??/i' => ' product care product details ',
            '/puwede bang palitan ang size\??/i' => ' exchange item size chart ',
            '/ano materials nito\??/i' => ' product details specifications ',
            '/puwede ba sa cod\??/i' => ' cash on delivery cod ',
            '/may discount ba\??/i' => ' discount promo voucher ',
            '/saan mag-?file ng complaint\??/i' => ' complaint contact support ',
            '/puwede bang mag-?escalate\??/i' => ' escalate issue contact support human agent ',
            '/salamat sa tulong/i' => ' thank you ',
            '/ok na po, thank you/i' => ' thank you ',
            '/may update na ba\??/i' => ' follow-up inquiry order status ',
            '/paano mag-?send ng proof\??/i' => ' send proof support return request ',
            '/kailangan ko ng assistance/i' => ' help me support assistance ',
            '/\b(order ko|nasaan na order ko)\b/i' => ' track my order order status ',
            '/\b(tracking number|delivery status|out for delivery|delayed order)\b/i' => ' tracking delivery status shipping status ',
            '/\b(kailan darating)\b/i' => ' delivery time shipping time eta ',
            '/\b(shipping fee)\b/i' => ' shipping fee delivery fee shipping cost ',
            '/\b(delivery address)\b/i' => ' change delivery address shipping address ',
            '/\b(cod|cash on delivery)\b/i' => ' cash on delivery cod ',
            '/\b(return item|paano mag return)\b/i' => ' return an item how do i return ',
            '/\b(refund status|kailan marerefund)\b/i' => ' refund time refund process ',
            '/\b(defective item)\b/i' => ' damaged item defective ',
            '/\b(wrong item)\b/i' => ' wrong item incorrect item ',
            '/\b(replacement)\b/i' => ' exchange item replacement ',
            '/\b(return policy)\b/i' => ' return policy ',
            '/\b(payment method)\b/i' => ' payment methods ',
            '/\b(bayad na pero hindi reflected)\b/i' => ' payment failed payment not reflected order confirmation ',
            '/\b(credit card)\b/i' => ' credit card credit/debit ',
            '/\b(gcash)\b/i' => ' gcash ',
            '/\b(maya)\b/i' => ' maya paymaya ',
            '/\b(payment failed)\b/i' => ' payment failed declined card ',
            '/\b(order confirmation)\b/i' => ' order confirmation order status ',
            '/\b(invoice)\b/i' => ' invoice payment receipt ',
            '/\b(forgot password)\b/i' => ' forgot password reset password ',
            '/\b(change password)\b/i' => ' reset password account settings ',
            '/\b(account verification)\b/i' => ' verify account account verification ',
            '/\b(email not received)\b/i' => ' email issue verification email ',
            '/\b(login problem)\b/i' => ' login issue login problem ',
            '/\b(update profile)\b/i' => ' profile update account settings ',
            '/\b(delete account)\b/i' => ' delete account close account ',
            '/\b(stock availability)\b/i' => ' in stock available stock ',
            '/\b(size chart)\b/i' => ' sizes size chart ',
            '/\b(product details)\b/i' => ' product details specifications specs ',
            '/\b(kulay available)\b/i' => ' colors available variants ',
            '/\b(specifications)\b/i' => ' specifications specs ',
            '/\b(warranty)\b/i' => ' warranty guarantee ',
            '/\b(original ba ito)\b/i' => ' authentic original product ',
            '/\b(customer service|help me|support|live chat|ticket number|follow-?up inquiry|escalate issue)\b/i' => ' contact support human agent ',
            '/\b(pa-help po|paano po ito|hindi gumagana|nag error)\b/i' => ' help support issue not working error ',
            '/\b(salamat|ok po|sige po|pasensya na)\b/i' => ' thank you ',
        ];
    }
}
