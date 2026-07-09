type BehavioralAssessment = {
    punctuality: string;
    mental_alertness: string;
    respect: string;
    neatness: string;
    politeness: string;
    honesty: string;
    relationship_with_peers: string;
    teamwork: string;
    perseverance: string;
};

interface Props {
    assessment: BehavioralAssessment;
}

export function BehavioralAssessmentTable({ assessment }: Props) {
    if (!assessment) {
        return (
            <div className="overflow-x-auto text-xs">
                <table className="w-full border-collapse border border-gray-300 text-xs">
                    <thead className="bg-slate-700 text-white">
                        <tr>
                            <th className="border border-gray-300 text-left whitespace-nowrap">
                                Behavior Assessment
                            </th>

                            <th className="w-12 border border-gray-300 text-center">
                                A
                            </th>
                            <th className="w-12 border border-gray-300 text-center">
                                B
                            </th>
                            <th className="w-12 border border-gray-300 text-center">
                                C
                            </th>
                            <th className="w-12 border border-gray-300 text-center">
                                D
                            </th>
                            <th className="w-12 border border-gray-300 text-center">
                                E
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Punctuality
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Mental Alertness
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Respect
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Neatness
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Politeness
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Honesty
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Relationship With Peers
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Teamwork
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                        <tr>
                            <td className="border border-gray-300 whitespace-nowrap">
                                Perseverance
                            </td>

                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                            <td className="border border-gray-300 text-center"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        );
    }

    const behaviours = [
        { label: 'Punctuality', value: assessment.punctuality },
        { label: 'Mental Alertness', value: assessment.mental_alertness },
        { label: 'Respect', value: assessment.respect },
        { label: 'Neatness', value: assessment.neatness },
        { label: 'Politeness', value: assessment.politeness },
        { label: 'Honesty', value: assessment.honesty },
        {
            label: 'Relationship with Peers',
            value: assessment.relationship_with_peers,
        },
        { label: 'Teamwork', value: assessment.teamwork },
        { label: 'Perseverance', value: assessment.perseverance },
    ];

    const grades = ['A', 'B', 'C', 'D', 'E'];

    return (
        <div className="overflow-x-auto text-xs">
            <table className="w-full border-collapse border border-gray-300 text-xs">
                <thead className="bg-slate-700 text-white">
                    <tr>
                        <th className="border border-gray-300 text-left whitespace-nowrap">
                            Behavior Assessment
                        </th>

                        {grades.map((grade) => (
                            <th
                                key={grade}
                                className="w-12 border border-gray-300 text-center"
                            >
                                {grade}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {behaviours.map((behaviour) => (
                        <tr key={behaviour.label}>
                            <td className="border border-gray-300 whitespace-nowrap">
                                {behaviour.label}
                            </td>

                            {grades.map((grade) => (
                                <td
                                    key={grade}
                                    className="border border-gray-300 text-center"
                                >
                                    {behaviour.value === grade ? '✓' : ''}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
