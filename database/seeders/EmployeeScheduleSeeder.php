<?php

namespace Database\Seeders;

use App\Models\EmployeeSchedule;
use App\Models\User;
use App\Models\Campaign;
use App\Models\Site;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeScheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Based on biometric names CSV file
     */
    public function run(): void
    {
        // Get or create sites
        $ph1 = Site::firstOrCreate(['name' => 'PH1']);
        $ph2 = Site::firstOrCreate(['name' => 'PH2']);
        $ph3 = Site::firstOrCreate(['name' => 'PH3']);

        // Get or create campaigns
        $copierSales = Campaign::firstOrCreate(['name' => 'Copier Sales']);
        $copierLg = Campaign::firstOrCreate(['name' => 'Copier LG']);
        $allstateBxo = Campaign::firstOrCreate(['name' => 'Allstate BXO']);
        $helix = Campaign::firstOrCreate(['name' => 'Helix']);
        $pso = Campaign::firstOrCreate(['name' => 'PSO']);
        $realEstate = Campaign::firstOrCreate(['name' => 'Real Estate']);
        $admin = Campaign::firstOrCreate(['name' => 'Admin/Utility']);

        // COPIER SALES - Night Shift (22:00-7:00)
        $this->createEmployeeSchedule('Joy', 'Lapidario', 'joy.lapidario@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Carissa', 'Ayes', 'carissa.ayes@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mark Dave', 'Candela', 'markdave.candela@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Niko', 'Contillo', 'niko.contillo@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Neil Adrian', 'Dela Pena', 'neiladrian.delapena@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Hannah Isabel', 'Delicano', 'hannahisabel.delicano@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Ruby Jean', 'Galangue', 'rubyjean.galangue@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Micah', 'Larion', 'micah.larion@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('John Paul', 'Oquino', 'johnpaul.oquino@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Lyka Gean', 'Soposo', 'lykagean.soposo@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Gerianne', 'Tisbe', 'gerianne.tisbe@example.com', $copierSales, $ph1, '22:00:00', '07:00:00');

        // COPIER LG - Night Shift (22:00-7:00)
        $this->createEmployeeSchedule('Jeanmarie', 'Abrio', 'jeanmarie.abrio@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Bernadette', 'Canonigo', 'bernadette.canonigo@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Leandro', 'Medino', 'leandro.medino@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Karla Dawn', 'Paredes', 'karladawn.paredes@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Amielyn', 'Abdon', 'amielyn.abdon@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jea Den', 'Abenion', 'jeaden.abenion@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Glenda', 'Antonio', 'glenda.antonio@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Kris', 'Arnaiz', 'kris.arnaiz@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Florence', 'Baylon', 'florence.baylon@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Maria Richela', 'Berdan', 'mariarichela.berdan@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Myla', 'Cabarliza', 'myla.cabarliza@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jerick Earven', 'Camarines', 'jearven.camarines@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Windelyn', 'Coquia', 'windelyn.coquia@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mary Ann', 'Dadula', 'maryann.dadula@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mariz', 'De Lara', 'mariz.delara@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Kristal', 'Delute', 'kristal.delute@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Grace', 'Ecaldre', 'grace.ecaldre@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Vicente Jr.', 'Erandio', 'vicentejr.erandio@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Janele', 'Esmade', 'janele.esmade@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Diomel', 'Esoy', 'diomel.esoy@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Anjolieza', 'Estojero', 'anjolieza.estojero@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Vien Izza', 'Justo', 'vienizza.justo@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('America', 'Laure', 'america.laure@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Dom', 'Lepasana', 'dom.lepasana@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Marjorie', 'Limpin', 'marjorie.limpin@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Angel May', 'Magcuro', 'angelmay.magcuro@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Nestor', 'Mangalao', 'nestor.mangalao@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mayedelle Jan', 'Marilla', 'mayedellejan.marilla@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Vanessa', 'Memoracion', 'vanessa.memoracion@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Kristine Joy', 'Navarro', 'kristinejoy.navarro@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('John Laurence', 'Ordilla', 'johnlaurence.ordilla@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Nicole', 'Otivar', 'nicole.otivar@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Aldwin', 'Postrero', 'aldwin.postrero@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('John Rey', 'Raquel', 'johnrey.raquel@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Vanessa', 'Renomeron', 'vanessa.renomeron@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mary Rose', 'Rosel', 'maryrose.rosel@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Dea Faith', 'Sampan', 'deafaith.sampan@example.com', $copierLg, $ph1, '22:00:00', '07:00:00');

        // ALLSTATE BXO - Night Shift (22:30-7:30)
        $this->createEmployeeSchedule('Calvin', 'Liporada', 'calvin.liporada@example.com', $allstateBxo, $ph1, '22:30:00', '07:30:00');
        $this->createEmployeeSchedule('Adrian Vic', 'Ogao-ogao', 'adrianvic.ogaoogao@example.com', $allstateBxo, $ph1, '22:30:00', '07:30:00');

        // HELIX - Various Shifts
        $this->createEmployeeSchedule('Shielamay', 'Elona', 'shielamay.elona@example.com', $helix, $ph1, '22:30:00', '07:30:00');
        $this->createEmployeeSchedule('Niño', 'Camasin', 'nino.camasin@example.com', $helix, $ph1, '22:00:00', '08:00:00');
        $this->createEmployeeSchedule('Jhonard Kyle', 'Guadayo', 'jhonardkyle.guadayo@example.com', $helix, $ph2, '22:30:00', '07:30:00');

        // PSO
        $this->createEmployeeSchedule('Ivy', 'Belisario', 'ivy.belisario@example.com', $pso, $ph1, '01:00:00', '10:00:00');
        $this->createEmployeeSchedule('Jenifer', 'Sala', 'jenifer.sala@example.com', $pso, $ph1, '23:00:00', '08:00:00');

        // REAL ESTATE - Night Shift (22:00-7:00 mostly)
        $this->createEmployeeSchedule('Ma Angelyn', 'Potenciano', 'maangelyn.potenciano@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Christian George', 'Bunales', 'christiangeorge.bunales@example.com', $realEstate, $ph2, '21:00:00', '06:00:00');
        $this->createEmployeeSchedule('Emmanuel', 'Aban', 'emmanuel.aban@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Pauline Eve', 'Abdon', 'paulineeve.abdon@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Aldwin', 'Abia', 'aldwin.abia@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Ralph Arthur', 'Abolencia', 'ralpharthur.abolencia@example.com', $realEstate, $ph2, '22:30:00', '07:30:00');
        $this->createEmployeeSchedule('Gerald', 'Aguilos', 'gerald.aguilos@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Manuel Jr.', 'Apura', 'manueljr.apura@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('John Gregory', 'Avelino', 'johngregory.avelino@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Yvonne Andrie', 'Balantad', 'yvonneandrie.balantad@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jaella', 'Balintong', 'jaella.balintong@example.com', $realEstate, $ph2, '00:00:00', '09:00:00');
        $this->createEmployeeSchedule('Llana Ashrah', 'Batawan', 'llanaashrah.batawan@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Elvina Grace', 'Bejo', 'elvinagrace.bejo@example.com', $realEstate, $ph2, '21:00:00', '06:00:00');
        $this->createEmployeeSchedule('Monette', 'Belleza', 'monette.belleza@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Catherine', 'Bigoy', 'catherine.bigoy@example.com', $realEstate, $ph2, '21:00:00', '08:00:00');
        $this->createEmployeeSchedule('Haji Fer', 'Cabillan', 'hajifer.cabillan@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mac Yhager', 'Campos', 'macyhager.campos@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Krenz', 'Chavez', 'krenz.chavez@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Rachel Jo', 'Cinco', 'racheljo.cinco@example.com', $realEstate, $ph1, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Gwen Marc Basti', 'Cordeta', 'gwenmarcbasti.cordeta@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Ella Mae', 'Coritana', 'ellamae.coritana@example.com', $realEstate, $ph2, '22:00:00', '08:00:00');
        $this->createEmployeeSchedule('Trisha Monique', 'Costa', 'trishamonique.costa@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Ariann', 'De La Cruz', 'ariann.delacruz@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Ma. Sophia Gwyneth', 'Dulay', 'sophiagwyneth.dulay@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Jonamay', 'Edradan', 'jonamay.edradan@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Mark', 'Esquierdo', 'mark.esquierdo@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jarold', 'Fabillar', 'jarold.fabillar@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Stephen Mark', 'Fevidal', 'stephenmark.fevidal@example.com', $realEstate, $ph1, '22:00:00', '08:00:00');
        $this->createEmployeeSchedule('Alice Gail', 'Genoguin', 'alicegail.genoguin@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Glen Allen', 'Gil', 'glenallen.gil@example.com', $realEstate, $ph2, '21:00:00', '06:00:00');
        $this->createEmployeeSchedule('Gee Ann', 'Gozum', 'geeann.gozum@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Nova Grace', 'Josep', 'novagrace.josep@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Christian', 'Lagramada', 'christian.lagramada@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Roger', 'Layson', 'roger.layson@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Rubelyn', 'Lopez', 'rubelyn.lopez@example.com', $realEstate, $ph2, '21:00:00', '07:00:00');
        $this->createEmployeeSchedule('Catherine Jade', 'Mancio', 'catherinej.mancio@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Rodel', 'Marasigan', 'rodel.marasigan@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Kimberly Ann', 'Masibag', 'kimberlyann.masibag@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Kate Christine', 'Mendez', 'katechristine.mendez@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Mae Rose', 'Miralles', 'maerose.miralles@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Earl Hans', 'Molino', 'earlhans.molino@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Judy Ann', 'Novilla', 'judyann.novilla@example.com', $realEstate, $ph2, '22:00:00', '08:00:00');
        $this->createEmployeeSchedule('Porferio', 'Opena', 'porferio.opena@example.com', $realEstate, $ph1, '21:00:00', '06:00:00');
        $this->createEmployeeSchedule('Diana', 'Orocay', 'diana.orocay@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Lyn', 'Refuerzo', 'lyn.refuerzo@example.com', $realEstate, $ph2, '22:30:00', '07:30:00');
        $this->createEmployeeSchedule('Rhea Mae', 'Retanal', 'rheamae.retanal@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Sofia Kassandra', 'Reyes', 'sofiakassandra.reyes@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Jessica', 'Robinios', 'jessica.robinios@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Joannah Salve', 'Robinios', 'joannahsalve.robinios@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Chiara Meg', 'Singzon', 'chiarameg.singzon@example.com', $realEstate, $ph2, '00:00:00', '09:00:00');
        $this->createEmployeeSchedule('Bibiano III', 'Supremo', 'bibianoiii.supremo@example.com', $realEstate, $ph1, '21:00:00', '07:00:00');
        $this->createEmployeeSchedule('Ria Mae', 'Tabuac', 'riamae.tabuac@example.com', $realEstate, $ph2, '22:30:00', '07:30:00');
        $this->createEmployeeSchedule('Ram Ram', 'Vanilla', 'ramram.vanilla@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Henrick', 'Villanueva', 'henrick.villanueva@example.com', $realEstate, $ph2, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jude Cyrus', 'Villanueva', 'judecyrus.villanueva@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Hannah Grace', 'Wasawas', 'hannahgrace.wasawas@example.com', $realEstate, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Tricia Mae', 'Wasawas', 'triciamae.wasawas@example.com', $realEstate, $ph1, '23:00:00', '08:00:00');
        $this->createEmployeeSchedule('Shanty Andrea', 'Yu', 'shantyandrea.yu@example.com', $realEstate, $ph2, '23:00:00', '08:00:00');

        // ADMIN/UTILITY - Various Shifts
        $this->createEmployeeSchedule('Althea', 'Atillo', 'althea.atillo@example.com', $admin, $ph1, '21:00:00', '06:00:00');
        $this->createEmployeeSchedule('April Mae', 'Baculanlan', 'aprilmae.baculanlan@example.com', $admin, $ph1, '21:30:00', '06:30:00');
        $this->createEmployeeSchedule('Hersam', 'Bunales', 'hersam.bunales@example.com', $admin, $ph2, '07:00:00', '17:00:00');
        $this->createEmployeeSchedule('Victor Nigel', 'Cabaluna', 'victornigel.cabaluna@example.com', $admin, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Ariston', 'Cabarliza', 'ariston.cabarliza@example.com', $admin, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Dennis', 'Ditchon', 'dennis.ditchon@example.com', $admin, $ph1, '20:00:00', '07:00:00');
        $this->createEmployeeSchedule('Jan Ramil', 'Intong', 'janramil.intong@example.com', $admin, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Edward', 'Madriaga', 'edward.madriaga@example.com', $admin, $ph1, '22:00:00', '09:00:00');
        $this->createEmployeeSchedule('Pinky Joyce', 'Molon', 'pinkyjoyce.molon@example.com', $admin, $ph1, '22:00:00', '07:00:00');
        $this->createEmployeeSchedule('Alejandro', 'Nodado', 'alejandro.nodado@example.com', $admin, $ph1, '20:30:00', '05:30:00');
        $this->createEmployeeSchedule('Charlie', 'Nuttal', 'charlie.nuttal@example.com', $admin, $ph3, '01:00:00', '11:00:00');
        $this->createEmployeeSchedule('Melvin', 'Tabao', 'melvin.tabao@example.com', $admin, $ph2, '15:00:00', '00:00:00');
    }

    /**
     * Helper method to create employee and schedule
     */
    private function createEmployeeSchedule(
        string $firstName,
        string $lastName,
        string $email,
        Campaign $campaign,
        Site $site,
        string $timeIn,
        string $timeOut
    ): void {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'middle_name' => null,
                'last_name' => $lastName,
                'password' => Hash::make('password'),
                'role' => 'Agent',
                'email_verified_at' => now(),
            ]
        );

        // Determine shift type based on time
        // Morning: 6 AM - 1:59 PM
        // Afternoon: 2 PM - 7:59 PM
        // Night: 8 PM - 5:59 AM
        $shiftType = 'night_shift';
        if ($timeIn >= '06:00:00' && $timeIn < '14:00:00') {
            $shiftType = 'morning_shift';
        } elseif ($timeIn >= '14:00:00' && $timeIn < '20:00:00') {
            $shiftType = 'afternoon_shift';
        }

        EmployeeSchedule::firstOrCreate(
            [
                'user_id' => $user->id,
                'shift_type' => $shiftType,
            ],
            [
                'campaign_id' => $campaign->id,
                'site_id' => $site->id,
                'scheduled_time_in' => $timeIn,
                'scheduled_time_out' => $timeOut,
                'work_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'grace_period_minutes' => 15,
                'is_active' => true,
                'effective_date' => '2025-01-01',
            ]
        );
    }
}
