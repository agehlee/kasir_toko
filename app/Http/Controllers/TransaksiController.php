<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetilPenjualan;
use App\Models\Pelanggan;
use App\Models\Penjualan;
use App\Models\Produk;
use App\Models\User;
use Jackiedo\Cart\Cart;

class TransaksiController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;

        $penjualans = Penjualan::leftJoin('pelanggans', 'penjualans.pelanggan_id', '=', 'pelanggans.id')
            ->join('users', 'users.id', '=', 'penjualans.user_id')
            ->select('penjualans.*', 'users.nama as nama_kasir', 'pelanggans.nama as nama_pelanggan')
            ->orderBy('penjualans.id', 'desc')
            ->when($search, function ($q, $search) {
                return $q->where('penjualans.nomor_transaksi', 'like', "%{$search}%");
            })
            ->paginate();

        if ($search) {
            $penjualans->appends(['search' => $search]);
        }

        return view('transaksi.index', [
            'penjualans' => $penjualans
        ]);
    }
    public function create(Request $request)
    {
        $pelanggans = Pelanggan::all(); 

        return view('transaksi.create', [
            'nama_kasir' => $request->user()->nama,
            'tanggal' => date('d F Y'),
            'pelanggans' => $pelanggans,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'cash' =>['required', 'numeric', 'gte:total_bayar'],
            'diskon' => ['nullable', 'numeric'] 
        ]);

        $user = $request->user();
        $lastPenjualan = Penjualan::orderBy('id','desc')->first();

        $cart = new Cart();
        $cart->name($user->id);

        $cartDetails = $cart->getDetails();
        $errorMessages = [];

        foreach ($cartDetails->get('items') as $item) {
            $produk = Produk::find($item->id);
            if ($produk->stok < $item->quantity) {
                $errorMessages[] = "Stok produk {$produk->nama_produk} tidak mencukupi (tersedia: {$produk->stok}).";
            }
        }

        if (!empty($errorMessages)) {
            return back()->withErrors(['stok' => $errorMessages])->withInput();
        }

        $subtotal = $cartDetails->get('subtotal');
        $total = $cartDetails->get('total');

        $diskon = $request->diskon ?? 0;
        if ($subtotal > 45000) {
            $diskon = 5000; 
            $total -= $diskon;
        }

        $kembalian = $request->cash - $total;

        $no = $lastPenjualan ? $lastPenjualan->id + 1 : 1;
        $no = sprintf("%04d", $no);

        $penjualanData = [
            'user_id' => $user->id,
            'nomor_transaksi' => date('Ymd').$no,
            'tanggal' => date('Y-m-d H:i:s'),
            'total' => $total,
            'tunai'=> $request->cash,
            'kembalian'=>$kembalian,
            'pajak' => $cartDetails->get('tax_amount'),
            'subtotal' => $cartDetails->get('subtotal'),
            'diskon' => $diskon 
        ];

        if ($request->has('pelanggan_id')) {
            $penjualanData['pelanggan_id'] = $request->pelanggan_id;
        }  else {
            $penjualanData['pelanggan_id'] = null; 
        }

        $penjualan = Penjualan::create($penjualanData);

        $allItems = $cartDetails->get('items');

        foreach ($allItems as $key => $value) {
            $item = $allItems->get($key);
            $produk = Produk::find($item->id);
            $produk->update(['stok' => $produk->stok - $item->quantity]);

            DetilPenjualan::create([
                'penjualan_id' => $penjualan->id,
                'produk_id' => $item->id,
                'jumlah' => $item->quantity,
                'harga_produk' => $item->price,
                'subtotal' => $item->subtotal,
            ]);
        }

        $cart->destroy();

        return redirect()->route('transaksi.show', ['transaksi'=> $penjualan->id]);
    }
    
    public function show(Request $request, Penjualan $transaksi)
    {
        $pelanggan = Pelanggan::find($transaksi->pelanggan_id);
        $user = User::find($transaksi->user_id);
        $detilPenjualan = DetilPenjualan::join('produks', 'produks.id', 'detil_penjualans.produk_id')
            ->select('detil_penjualans.*', 'nama_produk')
            ->where('penjualan_id', $transaksi->id)->get();

        $diskon = $transaksi->diskon;
        
        return view('transaksi.invoice', [
            'penjualan' => $transaksi,
            'pelanggan' => $pelanggan,
            'user' => $user,
            'detilPenjualan' => $detilPenjualan,
            'diskon' => $diskon, 
        ]);
    }

    public function destroy(Request $request, Penjualan $transaksi)
    {
        $allItems = DetilPenjualan::where('penjualan_id', $transaksi->id)->get();
    
        foreach ($allItems as $item) {
            $produk = Produk::find($item->produk_id);
            $produk->update([
                'stok' => $produk->stok + $item->jumlah
            ]);
        }
    
        $transaksi->update([
            'status' => 'batal'
        ]);
    
        return back()->with('destroy', 'success');
    }
    
    public function produk(Request $request)
    {
        $search = $request->search;
        $produks = Produk::select('id', 'kode_produk', 'nama_produk')
            ->when($search, function ($q, $search) {
                return $q->where('nama_produk', 'like', "%{$search}%");
            })
            ->orderBy('nama_produk')
            ->take(15)
            ->get();

        return response()->json($produks);
    }

    public function pelanggan(Request $request)
    {
        $search = $request->search;
        $pelanggans = Pelanggan::select('id', 'nama')
            ->when($search, function ($q, $search) {
                return $q->where('nama', 'like', "%{$search}%");
            })
            ->orderBy('nama')
            ->take(15)
            ->get();

        return response()->json($pelanggans);
    }

    public function addPelanggan(Request $request)
    {
        $request->validate([
            'id' => ['required', 'exists:pelanggans']
        ]);
        $pelanggan = Pelanggan::find($request->id);
    
        $cart = new Cart();
        $cart->name($request->user()->id);
    
        $cart->setExtraInfo([
            'pelanggan' => [
                'id' => $pelanggan->id,
                'nama' => $pelanggan->nama,
            ]
        ]);
    
        return response()->json(['message' => 'Berhasil.']);
    }

    public function cetak(Penjualan $transaksi)
    {
        $pelanggan = Pelanggan::find($transaksi->pelanggan_id);
        $user = User::find($transaksi->user_id);
        $detilPenjualan = DetilPenjualan::join('produks', 'produks.id', 'detil_penjualans.produk_id')
            ->select('detil_penjualans.*', 'nama_produk')
            ->where('penjualan_id', $transaksi->id)->get();
        
        return view('transaksi.cetak', [
            'penjualan' => $transaksi,
            'pelanggan' => $pelanggan,
            'user' => $user,
            'detilPenjualan' => $detilPenjualan
        ]);
    }
}